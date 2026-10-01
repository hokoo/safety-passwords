#!/usr/bin/env python3
"""Focused negative checks against an actual monitor-built release artifact."""

import argparse
import contextlib
import hashlib
import io
import json
import sys
import tempfile
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import package  # noqa: E402
import source  # noqa: E402


def digest(data):
    return hashlib.sha256(data).hexdigest()


def expect_failure(label, expected, callback):
    try:
        with contextlib.redirect_stdout(io.StringIO()):
            callback()
    except source.ReleaseError as error:
        if str(error) == expected:
            print("PASS release negative " + label)
            return
    raise source.ReleaseError("negative_check_failed_" + label)


def rewrite_archive(original, replacement=None, omission=None, addition=None):
    memory = io.BytesIO()
    with zipfile.ZipFile(io.BytesIO(original)) as before:
        with zipfile.ZipFile(memory, "w") as after:
            for info in before.infolist():
                if info.filename == omission:
                    continue
                content = before.read(info)
                if replacement and info.filename == replacement[0]:
                    content = replacement[1](content)
                after.writestr(info, content)
            if addition:
                info = zipfile.ZipInfo(addition[0], before.infolist()[0].date_time)
                info.create_system = 3
                info.external_attr = (0o100644 << 16)
                info.compress_type = zipfile.ZIP_DEFLATED
                after.writestr(info, addition[1])
    return memory.getvalue()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--zip", required=True)
    parser.add_argument("--manifest", required=True)
    parser.add_argument("--source-sha", required=True)
    args = parser.parse_args()
    original_zip = Path(args.zip).read_bytes()
    original_manifest = json.loads(Path(args.manifest).read_text(encoding="utf-8"))
    if original_manifest.get("source_sha") != args.source_sha:
        raise source.ReleaseError("fixture_source_mismatch")

    with tempfile.TemporaryDirectory(prefix="sp-release-negative-") as temporary:
        directory = Path(temporary)

        def validate(label, archive_data=original_zip, manifest_data=original_manifest,
                     expected_sha=None):
            archive = directory / (label + ".zip")
            manifest = directory / (label + ".json")
            archive.write_bytes(archive_data)
            manifest.write_text(json.dumps(manifest_data), encoding="utf-8")
            inputs = argparse.Namespace(source_sha=args.source_sha, zip=str(archive),
                                        manifest=str(manifest), tag=original_manifest["tag"] or None,
                                        prerelease=original_manifest["prerelease"],
                                        expected_zip_sha256=expected_sha)
            package.validate(inputs)

        with contextlib.redirect_stdout(io.StringIO()):
            validate("baseline", expected_sha=digest(original_zip))
        print("PASS release artifact baseline")

        expect_failure("source_sha", "malformed_source_sha",
                       lambda: source.source_metadata("not-a-commit"))

        fixture_repo = directory / "metadata-repo"
        source.git("init", "--quiet", str(fixture_repo))
        fixture_plugin = fixture_repo / "plugin-dir"
        fixture_plugin.mkdir()
        (fixture_plugin / "safety-passwords.php").write_text(
            "Version: 1.5\nconst VERSION = '1.6';\n", encoding="utf-8")
        (fixture_plugin / "readme.txt").write_text(
            "Stable tag: 1.5\n= 1.5 =\n", encoding="utf-8")
        source.git("add", "plugin-dir", cwd=fixture_repo)
        source.git("-c", "user.name=Fixture", "-c", "user.email=fixture@example.invalid",
                   "commit", "--quiet", "-m", "fixed fixture", cwd=fixture_repo)
        fixture_sha = source.git("rev-parse", "HEAD", cwd=fixture_repo).strip().decode("ascii")
        expect_failure("metadata_version", "version_mismatch",
                       lambda: source.source_metadata(fixture_sha, repo=fixture_repo))

        # Exercise the same exact-tag/master test against a disposable local bare
        # repository; no public tag or publication credentials are needed.
        remote = directory / "public-refs.git"
        source.git("init", "--bare", "--quiet", str(remote))
        source.git("fetch", "--quiet", "--no-tags", str(source.ROOT),
                   "+HEAD:refs/heads/master", cwd=remote)
        source.git("update-ref", "refs/tags/v" + original_manifest["version"],
                   args.source_sha, cwd=remote)
        public_repository = source.PUBLIC_REPOSITORY
        source.PUBLIC_REPOSITORY = str(remote)
        try:
            source.verify_publication(args.source_sha, "v" + original_manifest["version"])
            print("PASS release local publication refs")
            ancestor = source.git("rev-parse", args.source_sha + "^", cwd=remote).strip().decode("ascii")
            source.git("update-ref", "refs/tags/v" + original_manifest["version"],
                       ancestor, cwd=remote)
            expect_failure("tag_sha", "tag_source_mismatch",
                           lambda: source.verify_publication(
                               args.source_sha, "v" + original_manifest["version"]))
            source.git("update-ref", "refs/tags/v" + original_manifest["version"],
                       args.source_sha, cwd=remote)
            source.git("update-ref", "refs/heads/master", ancestor, cwd=remote)
            expect_failure("master_ancestry", "command_failed",
                           lambda: source.verify_publication(
                               args.source_sha, "v" + original_manifest["version"]))
        finally:
            source.PUBLIC_REPOSITORY = public_repository

        for bad_tag, prerelease in (("v1", False), ("v01.5", False),
                                    ("v1.5.0.0", False), ("v1.5-rc", True),
                                    ("v1.5-rc.1", False), ("v1.5", True)):
            expect_failure("tag", "malformed_tag" if bad_tag in
                           {"v1", "v01.5", "v1.5.0.0", "v1.5-rc"} else
                           "release_kind_mismatch",
                           lambda tag=bad_tag, pre=prerelease: source.parse_tag(tag, pre))

        changed = dict(original_manifest)
        changed["zip_sha256"] = "0" * 64
        expect_failure("checksum", "manifest_checksum_mismatch",
                       lambda: validate("checksum", manifest_data=changed))

        changed = dict(original_manifest)
        changed["version"] = "0.0"
        expect_failure("version", "manifest_source_mismatch",
                       lambda: validate("version", manifest_data=changed))

        expect_failure("trusted_checksum", "trusted_checksum_mismatch",
                       lambda: validate("trusted", expected_sha="0" * 64))

        def altered(label, archive_data, omitted=None, extra=None):
            manifest = dict(original_manifest)
            files = dict(original_manifest["files"])
            if omitted:
                files.pop(omitted)
            if extra:
                files[extra[0]] = digest(extra[1])
            manifest["files"] = files
            manifest["zip_sha256"] = digest(archive_data)
            return lambda: validate(label, archive_data=archive_data, manifest_data=manifest)

        autoload = "safety-passwords/vendor/autoload.php"
        without_vendor = rewrite_archive(original_zip, omission=autoload)
        expect_failure("missing_vendor", "missing_runtime_file",
                       altered("missing_vendor", without_vendor, omitted="vendor/autoload.php"))

        escape = ("safety-passwords/../escape.php", b"fixed synthetic data")
        unsafe = rewrite_archive(original_zip, addition=escape)
        expect_failure("zip_escape", "unsafe_path",
                       altered("zip_escape", unsafe, extra=("../escape.php", escape[1])))

        entry = "safety-passwords/safety-passwords.php"
        replaced = rewrite_archive(original_zip,
                                   replacement=(entry, lambda data: data + b"\n/* changed */\n"))
        manifest = dict(original_manifest)
        files = dict(original_manifest["files"])
        files["safety-passwords.php"] = digest(
            zipfile.ZipFile(io.BytesIO(replaced)).read(entry))
        manifest["files"] = files
        manifest["zip_sha256"] = digest(replaced)
        expect_failure("source_tamper", "archive_source_mismatch",
                       lambda: validate("source_tamper", archive_data=replaced,
                                        manifest_data=manifest))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except source.ReleaseError as error:
        print("FAIL release negative checks: " + str(error), file=sys.stderr)
        sys.exit(1)
    except Exception:
        print("FAIL release negative checks: unexpected_error", file=sys.stderr)
        sys.exit(1)
