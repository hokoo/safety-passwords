#!/usr/bin/env python3
"""Exercise delivery retry and failure paths with a validated local package."""

import argparse
import contextlib
import io
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import asset  # noqa: E402
import mirror  # noqa: E402
import package  # noqa: E402
import source  # noqa: E402
import svn as svn_helper  # noqa: E402


def quiet(command):
    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False)
    if result.returncode:
        raise source.ReleaseError("fixture_command_failed")
    return result.stdout


def expect(category, operation):
    try:
        with contextlib.redirect_stdout(io.StringIO()):
            operation()
    except source.ReleaseError as error:
        if str(error) == category:
            return
    raise source.ReleaseError("expected_failure_missing")


def args_for(inputs, **extra):
    values = dict(source_sha=inputs.source_sha, tag=inputs.tag, prerelease=False,
                  zip=inputs.zip, manifest=inputs.manifest,
                  expected_zip_sha256=inputs.expected_zip_sha256)
    values.update(extra)
    return argparse.Namespace(**values)


def adjacent_version(version, newer):
    parts = [int(part) for part in version.split(".")]
    if newer:
        parts[0] += 1
    else:
        index = next((index for index in range(len(parts) - 1, -1, -1)
                      if parts[index]), None)
        if index is None:
            raise source.ReleaseError("fixture_version_floor")
        parts[index] -= 1
        parts[index + 1:] = [0] * (len(parts) - index - 1)
    return ".".join(map(str, parts))


def check_asset(inputs):
    files = {"preexisting-notes.txt": b"fixed unrelated asset"}
    failed_upload = [False]

    def fake_gh(*argv):
        if argv[:2] == ("release", "view"):
            return json.dumps(dict(tagName=inputs.tag, isDraft=False, isPrerelease=False,
                                   assets=[{"name": name} for name in files])).encode()
        if argv[:2] == ("release", "download"):
            name = argv[argv.index("--pattern") + 1]
            Path(argv[argv.index("--dir") + 1], name).write_bytes(files[name])
            return b""
        if argv[:2] == ("release", "upload"):
            path = Path(argv[3])
            if path.name == asset.MANIFEST_NAME and failed_upload[0]:
                failed_upload[0] = False
                raise source.ReleaseError("github_command_failed")
            files[path.name] = path.read_bytes()
            return b""
        raise source.ReleaseError("fixture_unknown_github_call")

    original_gh = asset.gh
    asset.gh = fake_gh
    try:
        with contextlib.redirect_stdout(io.StringIO()):
            asset.operate(args_for(inputs, operation="preflight"))
        if len(files) != 1:
            raise source.ReleaseError("github_preflight_mutated")
        failed_upload[0] = True
        expect("github_command_failed", lambda: asset.operate(args_for(inputs, operation="publish")))
        if asset.ZIP_NAME not in files or asset.MANIFEST_NAME in files:
            raise source.ReleaseError("github_partial_state_missing")
        with contextlib.redirect_stdout(io.StringIO()):
            asset.operate(args_for(inputs, operation="publish"))
            asset.operate(args_for(inputs, operation="publish"))
        if len(files) != 3 or files["preexisting-notes.txt"] != b"fixed unrelated asset":
            raise source.ReleaseError("github_asset_preservation_failed")
        files[asset.ZIP_NAME] = b"fixed mismatch"
        expect("existing_github_asset_mismatch",
               lambda: asset.operate(args_for(inputs, operation="preflight")))
    finally:
        asset.gh = original_gh
    print("PASS release mocked GitHub asset retry and mismatch")


def git(*argv, cwd=None):
    return source.git(*argv, cwd=cwd or source.ROOT)


def check_mirror(inputs, directory):
    remote = directory / "mirror.git"
    seed = directory / "mirror-seed"
    git("init", "--bare", "--quiet", str(remote))
    git("init", "--quiet", str(seed))
    older = adjacent_version(inputs.version, False)
    newer = adjacent_version(inputs.version, True)
    (seed / "readme.txt").write_text("Stable tag: " + older + "\n", encoding="utf-8")
    git("add", "readme.txt", cwd=seed)
    git("-c", "user.name=Fixture", "-c", "user.email=fixture@example.invalid",
        "commit", "--quiet", "-m", "Update plugin build from release v" + older, cwd=seed)
    git("branch", "-M", "stable", cwd=seed)
    git("remote", "add", "origin", str(remote), cwd=seed)
    git("push", "--quiet", "origin", "stable", cwd=seed)
    parameters = args_for(inputs, branch="stable", remote=str(remote), operation="preflight")
    before = git("rev-parse", "refs/heads/stable", cwd=remote).decode("ascii").strip()
    with contextlib.redirect_stdout(io.StringIO()):
        mirror.operate(parameters)
    if git("rev-parse", "refs/heads/stable", cwd=remote).decode("ascii").strip() != before:
        raise source.ReleaseError("mirror_preflight_mutated")
    parameters.operation = "publish"
    with contextlib.redirect_stdout(io.StringIO()):
        mirror.operate(parameters)
    published = git("rev-parse", "refs/heads/stable", cwd=remote)
    snapshot = directory / "mirror-published"
    git("clone", "--quiet", "--branch", "stable", str(remote), str(snapshot))
    actual = mirror.branch_files(snapshot)
    expected = package.validated_archive(Path(inputs.zip), package.committed_epoch(inputs.source_sha))
    differences = sorted(name for name in set(actual) | set(expected)
                         if actual.get(name) != expected.get(name))
    if differences:
        raise source.ReleaseError("mirror_published_tree_diff:" + ",".join(differences[:5]))
    with contextlib.redirect_stdout(io.StringIO()):
        mirror.operate(parameters)
    if git("rev-parse", "refs/heads/stable", cwd=remote) != published:
        raise source.ReleaseError("mirror_retry_mutated")
    if not (mirror.release_order("v" + inputs.version + "-beta.2") <
            mirror.release_order("v" + inputs.version + "-rc.1") <
            mirror.release_order("v" + inputs.version + "-rc.2")):
        raise source.ReleaseError("mirror_prerelease_order_failed")
    git("fetch", "--quiet", "origin", "stable", cwd=seed)
    git("reset", "--hard", "FETCH_HEAD", cwd=seed)
    (seed / "fixture-mismatch.txt").write_text("fixed mismatch", encoding="utf-8")
    git("add", "fixture-mismatch.txt", cwd=seed)
    git("-c", "user.name=Fixture", "-c", "user.email=fixture@example.invalid",
        "commit", "--quiet", "-m", "fixed fixture mismatch", cwd=seed)
    git("push", "--quiet", "origin", "stable", cwd=seed)
    expect("existing_mirror_version_mismatch", lambda: mirror.operate(parameters))
    git("--git-dir=" + str(remote), "update-ref", "refs/heads/stable", before)
    git("reset", "--hard", before, cwd=seed)
    (seed / "readme.txt").write_text("Stable tag: " + newer + "\n", encoding="utf-8")
    git("add", "readme.txt", cwd=seed)
    git("-c", "user.name=Fixture", "-c", "user.email=fixture@example.invalid",
        "commit", "--quiet", "-m", "Safety Passwords release v" + newer, cwd=seed)
    git("push", "--quiet", "origin", "stable", cwd=seed)
    parameters.operation = "preflight"
    expect("mirror_downgrade", lambda: mirror.operate(parameters))
    print("PASS release local mirror retry, mismatch and downgrade")


def svn_revision(repository):
    return quiet(["svnlook", "youngest", str(repository)]).strip()


def seed_svn(repository, version):
    quiet(["svnadmin", "create", str(repository)])
    url = repository.as_uri()
    quiet(["svn", "mkdir", url + "/trunk", url + "/tags", url + "/assets",
           "-m", "fixed fixture layout"])
    with tempfile.TemporaryDirectory(prefix="sp-release-svn-seed-") as checkout:
        quiet(["svn", "checkout", url, checkout])
        (Path(checkout) / "trunk/readme.txt").write_text("Stable tag: " + version + "\n",
                                                        encoding="utf-8")
        (Path(checkout) / "assets/banner.png").write_bytes(b"fixed existing asset")
        quiet(["svn", "add", str(Path(checkout) / "trunk/readme.txt"),
               str(Path(checkout) / "assets/banner.png")])
        quiet(["svn", "commit", "-m", "fixed fixture seed", checkout])
    return url


def check_svn(inputs, directory):
    repository = directory / "wordpress-svn"
    url = seed_svn(repository, adjacent_version(inputs.version, False))
    params = args_for(inputs, version=inputs.version, svn_url=url, dry_run=True)
    before = svn_revision(repository)
    with contextlib.redirect_stdout(io.StringIO()):
        svn_helper.deploy(params)
    if svn_revision(repository) != before:
        raise source.ReleaseError("svn_dry_run_mutated")
    params.dry_run = False
    with contextlib.redirect_stdout(io.StringIO()):
        svn_helper.deploy(params)
    published = svn_revision(repository)
    if published == before:
        raise source.ReleaseError("svn_deploy_missing")
    if quiet(["svn", "cat", url + "/assets/banner.png"]) != b"fixed existing asset":
        raise source.ReleaseError("svn_assets_not_preserved")
    with contextlib.redirect_stdout(io.StringIO()):
        svn_helper.deploy(params)
    if svn_revision(repository) != published:
        raise source.ReleaseError("svn_retry_mutated")
    with tempfile.TemporaryDirectory(prefix="sp-release-svn-tamper-") as checkout:
        quiet(["svn", "checkout", url, checkout])
        (Path(checkout) / "tags" / inputs.version / "readme.txt").write_bytes(
            ("Stable tag: " + inputs.version + "\nchanged").encode())
        quiet(["svn", "commit", "-m", "fixed fixture mismatch", checkout])
    expect("existing_svn_tag_mismatch", lambda: svn_helper.deploy(params))
    newer = directory / "newer-svn"
    newer_url = seed_svn(newer, adjacent_version(inputs.version, True))
    params.svn_url = newer_url
    expect("svn_downgrade_or_reused_version", lambda: svn_helper.deploy(params))
    print("PASS release local SVN dry run, retry, mismatch and downgrade")


def main():
    phase = "arguments"
    try:
        parser = argparse.ArgumentParser(description=__doc__)
        parser.add_argument("--source-sha", required=True)
        parser.add_argument("--tag")
        parser.add_argument("--prerelease", action="store_true")
        parser.add_argument("--zip", required=True)
        parser.add_argument("--manifest", required=True)
        parser.add_argument("--expected-zip-sha256", required=True)
        inputs = parser.parse_args()
        phase = "artifact"
        with contextlib.redirect_stdout(io.StringIO()):
            package.validate(inputs)
        try:
            original = json.loads(Path(inputs.manifest).read_text(encoding="utf-8"))
            version = original["version"]
        except (OSError, ValueError, KeyError, TypeError):
            raise source.ReleaseError("fixture_manifest_unavailable")
        if not isinstance(version, str) or not source.VERSION_RE.fullmatch(version):
            raise source.ReleaseError("fixture_version_invalid")
        with tempfile.TemporaryDirectory(prefix="sp-release-delivery-") as temporary:
            fixture_manifest = Path(temporary) / asset.MANIFEST_NAME
            original["tag"] = "v" + version
            original["prerelease"] = False
            fixture_manifest.write_text(json.dumps(original, sort_keys=True, separators=(",", ":")) + "\n",
                                        encoding="utf-8")
            fixture = argparse.Namespace(source_sha=inputs.source_sha, tag="v" + version,
                                         prerelease=False, zip=inputs.zip, manifest=str(fixture_manifest),
                                         expected_zip_sha256=inputs.expected_zip_sha256, version=version)
            with contextlib.redirect_stdout(io.StringIO()):
                package.validate(fixture)
            phase = "github"
            check_asset(fixture)
            phase = "mirror"
            template = Path(temporary) / "empty-git-template"
            template.mkdir()
            previous_template = os.environ.get("GIT_TEMPLATE_DIR")
            os.environ["GIT_TEMPLATE_DIR"] = str(template)
            try:
                check_mirror(fixture, Path(temporary))
            finally:
                if previous_template is None:
                    os.environ.pop("GIT_TEMPLATE_DIR", None)
                else:
                    os.environ["GIT_TEMPLATE_DIR"] = previous_template
            phase = "svn"
            check_svn(fixture, Path(temporary))
    except source.ReleaseError:
        raise
    except Exception as error:
        raise source.ReleaseError("unexpected_" + phase + "_" + type(error).__name__) from None
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except source.ReleaseError as error:
        print("FAIL release delivery checks: " + str(error), file=sys.stderr)
        sys.exit(1)
    except Exception:
        print("FAIL release delivery checks: unexpected_error", file=sys.stderr)
        sys.exit(1)
