#!/usr/bin/env python3
"""Verify the committed source and, for publication, its public tag and master."""

import argparse
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
PUBLIC_REPOSITORY = "https://github.com/hokoo/safety-passwords.git"
SHA_RE = re.compile(r"[0-9a-f]{40}\Z")
NUMBER = r"(?:0|[1-9][0-9]*)"
VERSION_RE = re.compile(rf"{NUMBER}\.{NUMBER}(?:\.{NUMBER})?\Z")
TAG_RE = re.compile(rf"v?({NUMBER}\.{NUMBER}(?:\.{NUMBER})?)(?:-(beta|rc)\.({NUMBER}))?\Z")


class ReleaseError(Exception):
    pass


def git_env():
    env = {key: value for key, value in os.environ.items()
           if not key.startswith("GIT_CONFIG_") and key not in
           {"GIT_ASKPASS", "SSH_ASKPASS", "GIT_TERMINAL_PROMPT"}}
    env.update(GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL=os.devnull,
               GIT_TERMINAL_PROMPT="0", GIT_ASKPASS=os.devnull,
               SSH_ASKPASS=os.devnull)
    return env


def command(args, cwd=ROOT):
    result = subprocess.run(args, cwd=str(cwd), env=git_env(), stdout=subprocess.PIPE,
                            stderr=subprocess.DEVNULL, check=False)
    if result.returncode:
        raise ReleaseError("command_failed")
    return result.stdout


def git(*args, cwd=ROOT):
    return command(["git", "-c", "credential.helper=", *args], cwd=cwd)


def check_sha(source_sha, repo=ROOT):
    if not SHA_RE.fullmatch(source_sha):
        raise ReleaseError("malformed_source_sha")
    if git("rev-parse", "--verify", source_sha + "^{commit}", cwd=repo).strip().decode("ascii") != source_sha:
        raise ReleaseError("source_not_commit")


def parse_tag(tag, prerelease=False):
    match = TAG_RE.fullmatch(tag)
    if not match:
        raise ReleaseError("malformed_tag")
    is_prerelease = match.group(2) is not None
    if is_prerelease != prerelease:
        raise ReleaseError("release_kind_mismatch")
    return match.group(1)


def source_blob(source_sha, path, repo=ROOT):
    if not path.startswith("plugin-dir/") or ":" in path or "\n" in path:
        raise ReleaseError("unsafe_source_path")
    return git("show", source_sha + ":" + path, cwd=repo)


def single_value(pattern, content, category):
    matches = re.findall(pattern, content, re.MULTILINE)
    if len(matches) != 1:
        raise ReleaseError(category)
    return matches[0]


def source_metadata(source_sha, tag=None, prerelease=False, repo=ROOT):
    check_sha(source_sha, repo)
    try:
        entry = source_blob(source_sha, "plugin-dir/safety-passwords.php", repo).decode("utf-8")
        readme = source_blob(source_sha, "plugin-dir/readme.txt", repo).decode("utf-8")
    except UnicodeDecodeError:
        raise ReleaseError("invalid_source_metadata")
    header = single_value(r"^Version:\s*([^\s]+)\s*$", entry, "plugin_header_version")
    constant = single_value(r"^const VERSION\s*=\s*'([^']+)';\s*$", entry,
                            "plugin_constant_version")
    stable = single_value(r"^Stable tag:\s*([^\s]+)\s*$", readme, "readme_stable_tag")
    if not VERSION_RE.fullmatch(header) or header != constant or header != stable:
        raise ReleaseError("version_mismatch")
    if len(re.findall(r"^= " + re.escape(header) + r" =$", readme, re.MULTILINE)) != 1:
        raise ReleaseError("changelog_version_mismatch")
    if tag is not None and parse_tag(tag, prerelease) != header:
        raise ReleaseError("tag_version_mismatch")
    elif tag is None and prerelease:
        raise ReleaseError("prerelease_requires_tag")
    return header


def verify_publication(source_sha, tag, prerelease=False):
    source_metadata(source_sha, tag, prerelease)
    with tempfile.TemporaryDirectory(prefix="sp-release-ref-") as temporary:
        bare = Path(temporary) / "refs.git"
        git("init", "--bare", "--quiet", str(bare))
        git("fetch", "--quiet", "--force", "--no-tags", "--no-recurse-submodules",
            PUBLIC_REPOSITORY,
            "+refs/tags/" + tag + ":refs/tags/" + tag,
            "+refs/heads/master:refs/heads/master", cwd=bare)
        remote_tag_sha = git("rev-parse", "--verify", "refs/tags/" + tag + "^{commit}",
                             cwd=bare).strip().decode("ascii")
        if remote_tag_sha != source_sha:
            raise ReleaseError("tag_source_mismatch")
        git("merge-base", "--is-ancestor", source_sha, "refs/heads/master", cwd=bare)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("verify", choices=["verify"])
    parser.add_argument("--source-sha", required=True)
    parser.add_argument("--tag")
    parser.add_argument("--prerelease", action="store_true")
    parser.add_argument("--publication", action="store_true")
    args = parser.parse_args()
    try:
        if args.publication:
            if not args.tag:
                raise ReleaseError("publication_requires_tag")
            verify_publication(args.source_sha, args.tag, args.prerelease)
        else:
            source_metadata(args.source_sha, args.tag, args.prerelease)
    except ReleaseError as error:
        print("FAIL release source: " + str(error), file=sys.stderr)
        return 1
    except (OSError, ValueError, subprocess.SubprocessError):
        print("FAIL release source: unavailable", file=sys.stderr)
        return 1
    print("PASS release source")
    return 0


if __name__ == "__main__":
    sys.exit(main())
