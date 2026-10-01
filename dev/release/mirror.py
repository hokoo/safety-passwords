#!/usr/bin/env python3
"""Guard and update the stable or prerelease distribution branch without force."""

import argparse
import contextlib
import io
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

import package
import source
from source import ReleaseError


PUBLIC_REPOSITORY = "https://github.com/hokoo/safety-passwords.git"


def fail(category):
    raise ReleaseError(category)


def release_order(tag):
    match = source.TAG_RE.fullmatch(tag)
    if not match:
        fail("invalid_mirror_tag")
    base = tuple((list(map(int, match.group(1).split("."))) + [0])[:3])
    channel = match.group(2)
    return base + ({None: (3, 0), "rc": (2, int(match.group(3) or 0)),
                    "beta": (1, int(match.group(3) or 0))}[channel])


def check_remote(value):
    if value == PUBLIC_REPOSITORY:
        return True
    if not value.startswith("/tmp/") or ".." in Path(value).parts or not Path(value).is_dir():
        fail("unsafe_mirror_remote")
    return False


def branch_files(checkout):
    output = {}
    for path in checkout.rglob("*"):
        if ".git" in path.relative_to(checkout).parts:
            continue
        if path.is_symlink():
            fail("mirror_symlink")
        if path.is_file():
            output[path.relative_to(checkout).as_posix()] = path.read_bytes()
    return output


def current_tag(checkout, branch):
    subject = source.git("log", "-1", "--format=%s", cwd=checkout).decode("utf-8").strip()
    match = re.fullmatch(r"(?:Update plugin build from release |Safety Passwords release )(.+)", subject)
    if match and source.TAG_RE.fullmatch(match.group(1)):
        return match.group(1), True
    readme = (checkout / "readme.txt")
    if not readme.is_file():
        fail("mirror_version_unavailable")
    matches = re.findall(r"^Stable tag:\s*([^\s]+)\s*$", readme.read_text(encoding="utf-8"), re.MULTILINE)
    if len(matches) != 1 or not source.VERSION_RE.fullmatch(matches[0]):
        fail("mirror_version_unavailable")
    return matches[0], branch != "pre-release"


def git_push(checkout, branch, official):
    if official and not os.environ.get("GH_TOKEN"):
        fail("missing_github_token")
    command = ["git", "-c", "credential.helper=", "-c", "core.askPass=/bin/false"]
    if official:
        command.extend(["-c", "credential.helper=!gh auth git-credential"])
    command.extend(["push", "origin", "HEAD:refs/heads/" + branch])
    result = subprocess.run(command, cwd=str(checkout), env=source.git_env(),
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
    if result.returncode:
        fail("mirror_push_rejected")


def operate(args):
    source.source_metadata(args.source_sha, args.tag, args.prerelease)
    if args.branch != ("pre-release" if args.prerelease else "stable"):
        fail("mirror_channel_mismatch")
    official = check_remote(args.remote)
    inputs = argparse.Namespace(source_sha=args.source_sha, tag=args.tag,
                                prerelease=args.prerelease, zip=args.zip,
                                manifest=args.manifest,
                                expected_zip_sha256=args.expected_zip_sha256)
    with contextlib.redirect_stdout(io.StringIO()):
        package.validate(inputs)
    desired = package.validated_archive(Path(args.zip), package.committed_epoch(args.source_sha))
    with tempfile.TemporaryDirectory(prefix="sp-release-mirror-") as temporary:
        checkout = Path(temporary) / "checkout"
        source.git("init", "--quiet", str(checkout))
        # The validated ZIP is the distribution contract. Keep even CRLF license
        # files byte-exact when Git stages the temporary mirror checkout.
        attributes = checkout / ".git/info/attributes"
        attributes.parent.mkdir(parents=True, exist_ok=True)
        attributes.write_text("* -text\n", encoding="ascii")
        source.git("remote", "add", "origin", args.remote, cwd=checkout)
        source.git("fetch", "--quiet", "--no-tags", "--depth=1", "origin",
                   "+refs/heads/" + args.branch + ":refs/remotes/origin/" + args.branch,
                   cwd=checkout)
        source.git("checkout", "--quiet", "-b", args.branch,
                   "refs/remotes/origin/" + args.branch, cwd=checkout)
        previous_tag, exact_channel = current_tag(checkout, args.branch)
        if (args.prerelease and not exact_channel and
                release_order(args.tag)[:3] == release_order(previous_tag)[:3]):
            fail("mirror_prerelease_order_unknown")
        if release_order(args.tag) < release_order(previous_tag):
            fail("mirror_downgrade")
        current = branch_files(checkout)
        if release_order(args.tag) == release_order(previous_tag):
            if current != desired:
                fail("existing_mirror_version_mismatch")
            print("PASS release mirror exact retry")
            return
        if args.operation == "preflight":
            print("PASS release mirror preflight")
            return
        for entry in checkout.iterdir():
            if entry.name == ".git":
                continue
            if entry.is_dir():
                shutil.rmtree(entry)
            else:
                entry.unlink()
        for relative, content in desired.items():
            target = checkout / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content)
        source.git("add", "--force", "--all", cwd=checkout)
        source.git("-c", "user.name=github-actions[bot]",
                   "-c", "user.email=41898282+github-actions[bot]@users.noreply.github.com",
                   "commit", "--quiet", "-m", "Safety Passwords release " + args.tag,
                   cwd=checkout)
        git_push(checkout, args.branch, official)
    print("PASS release mirror update")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("operation", choices=["preflight", "publish"])
    parser.add_argument("--source-sha", required=True)
    parser.add_argument("--tag", required=True)
    parser.add_argument("--zip", required=True)
    parser.add_argument("--manifest", required=True)
    parser.add_argument("--expected-zip-sha256", required=True)
    parser.add_argument("--branch", choices=["stable", "pre-release"], required=True)
    parser.add_argument("--prerelease", action="store_true")
    parser.add_argument("--remote", default=PUBLIC_REPOSITORY)
    args = parser.parse_args()
    try:
        operate(args)
    except ReleaseError as error:
        print("FAIL release mirror: " + str(error), file=sys.stderr)
        return 1
    except Exception:
        print("FAIL release mirror: unexpected_error", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
