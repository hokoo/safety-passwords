#!/usr/bin/env python3
"""Deliver one validated stable ZIP to SVN with an exact-content retry guard."""

import argparse
import contextlib
import io
import os
import re
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path
from urllib.parse import urlparse

import package
from source import ReleaseError, source_metadata


OFFICIAL_URL = "https://plugins.svn.wordpress.org/safety-passwords"


def fail(category):
    raise ReleaseError(category)


def svn(*arguments, cwd=None, password=None, allow_failure=False):
    result = subprocess.run(["svn", "--non-interactive", "--no-auth-cache", *arguments],
                            cwd=str(cwd) if cwd else None,
                            input=(password + "\n").encode() if password is not None else None,
                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False)
    if result.returncode and not allow_failure:
        fail("svn_command_failed")
    return result


def check_url(url):
    if url == OFFICIAL_URL:
        return True
    parsed = urlparse(url)
    if (parsed.scheme != "file" or parsed.netloc or parsed.query or parsed.fragment or
            not parsed.path.startswith("/tmp/") or
            any(part in {"", ".", ".."} for part in parsed.path[1:].split("/"))):
        fail("unsafe_svn_url")
    return False


def files_at(root):
    if root.is_symlink() or not root.is_dir():
        fail("invalid_svn_tree")
    output = {}
    for current, directories, files in os.walk(root, topdown=True, followlinks=False):
        directories[:] = [name for name in directories if name != ".svn"]
        for name in directories:
            if (Path(current) / name).is_symlink():
                fail("svn_tree_symlink")
        for name in files:
            item = Path(current) / name
            if item.is_symlink() or not item.is_file():
                fail("svn_tree_special_file")
            output[item.relative_to(root).as_posix()] = item.read_bytes()
    return output


def version_tuple(value):
    if not re.fullmatch(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))?", value):
        fail("invalid_svn_version")
    parts = [int(part) for part in value.split(".")]
    return tuple((parts + [0])[:3])


def trunk_version(content):
    matches = re.findall(rb"^Stable tag:\s*([^\s]+)\s*$", content, re.MULTILINE)
    if len(matches) != 1:
        fail("missing_svn_stable_tag")
    try:
        return matches[0].decode("ascii")
    except UnicodeDecodeError:
        fail("invalid_svn_version")


def sync_trunk(root, desired):
    required_dirs = {""}
    for name in desired:
        parts = name.split("/")
        required_dirs.update("/".join(parts[:index]) for index in range(1, len(parts)))
    for current, directories, files in os.walk(root, topdown=True):
        directories[:] = [name for name in directories if name != ".svn"]
        for name in list(directories):
            item = Path(current) / name
            relative = item.relative_to(root).as_posix()
            if relative not in required_dirs:
                svn("delete", "--force", str(item))
                directories.remove(name)
        for name in files:
            item = Path(current) / name
            if item.relative_to(root).as_posix() not in desired:
                svn("delete", "--force", str(item))
    for relative, content in sorted(desired.items()):
        target = root / relative
        existed = target.exists()
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)
        if not existed:
            svn("add", "--parents", str(target))


def check_changed_paths(working_copy, version):
    xml = svn("status", "--xml", str(working_copy)).stdout
    try:
        entries = ET.fromstring(xml).findall(".//entry")
    except ET.ParseError:
        fail("invalid_svn_status")
    for entry in entries:
        path = Path(entry.attrib.get("path", "")).resolve()
        try:
            relative = path.relative_to(working_copy.resolve()).as_posix()
        except ValueError:
            fail("svn_change_outside_checkout")
        if not (relative == "trunk" or relative.startswith("trunk/") or
                relative == "tags/" + version or relative.startswith("tags/" + version + "/")):
            fail("svn_change_outside_release_paths")
        status = entry.find("wc-status")
        if status is None or status.attrib.get("item") in {"conflicted", "obstructed", "incomplete"}:
            fail("unsafe_svn_status")


def deploy(args):
    if args.prerelease:
        fail("prerelease_cannot_deploy_svn")
    version = source_metadata(args.source_sha, args.tag, False)
    if args.version != version:
        fail("svn_version_mismatch")
    official = check_url(args.svn_url)
    inputs = argparse.Namespace(source_sha=args.source_sha, tag=args.tag, prerelease=False,
                                zip=args.zip, manifest=args.manifest,
                                expected_zip_sha256=args.expected_zip_sha256)
    with contextlib.redirect_stdout(io.StringIO()):
        package.validate(inputs)
    desired = package.validated_archive(Path(args.zip), package.committed_epoch(args.source_sha))
    with tempfile.TemporaryDirectory(prefix="sp-release-svn-") as temporary:
        working_copy = Path(temporary) / "checkout"
        svn("checkout", "--depth", "immediates", args.svn_url, str(working_copy))
        trunk = working_copy / "trunk"
        tags = working_copy / "tags"
        if not trunk.is_dir() or not tags.is_dir():
            fail("missing_svn_layout")
        svn("update", "--set-depth", "infinity", str(trunk))
        svn("update", "--set-depth", "immediates", str(tags))
        current = files_at(trunk)
        current_version = trunk_version(current.get("readme.txt", b""))
        tag_directory = tags / version
        if tag_directory.exists():
            svn("update", "--set-depth", "infinity", str(tag_directory))
            if files_at(tag_directory) != desired or current != desired:
                fail("existing_svn_tag_mismatch")
            print("PASS release SVN exact retry")
            return
        if version_tuple(version) <= version_tuple(current_version):
            fail("svn_downgrade_or_reused_version")
        sync_trunk(trunk, desired)
        svn("copy", str(trunk), str(tag_directory))
        check_changed_paths(working_copy, version)
        if args.dry_run:
            print("PASS release SVN dry run")
            return
        if official:
            username = os.environ.get("WPORG_USERNAME", "")
            password = os.environ.get("WPORG_PASSWORD", "")
            if not username or not password:
                fail("missing_wordpress_org_credentials")
            svn("commit", "--username", username, "--password-from-stdin",
                "-m", "Safety Passwords " + version, str(working_copy), password=password)
        else:
            svn("commit", "-m", "Safety Passwords " + version, str(working_copy))
    print("PASS release SVN deploy")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-sha", required=True)
    parser.add_argument("--tag", required=True)
    parser.add_argument("--version", required=True)
    parser.add_argument("--zip", required=True)
    parser.add_argument("--manifest", required=True)
    parser.add_argument("--expected-zip-sha256", required=True)
    parser.add_argument("--svn-url", default=OFFICIAL_URL)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--prerelease", action="store_true")
    args = parser.parse_args()
    try:
        deploy(args)
    except ReleaseError as error:
        print("FAIL release SVN: " + str(error), file=sys.stderr)
        return 1
    except Exception:
        print("FAIL release SVN: unexpected_error", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
