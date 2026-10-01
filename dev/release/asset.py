#!/usr/bin/env python3
"""Preflight or upload exact assets to an existing published GitHub release."""

import argparse
import contextlib
import hashlib
import io
import json
import subprocess
import sys
import tempfile
from pathlib import Path

import package
from source import ReleaseError, source_metadata


REPOSITORY = "hokoo/safety-passwords"
ZIP_NAME = package.ARCHIVE_NAME
MANIFEST_NAME = package.MANIFEST_NAME


def fail(category):
    raise ReleaseError(category)


def gh(*arguments):
    result = subprocess.run(["gh", *arguments], stdout=subprocess.PIPE,
                            stderr=subprocess.DEVNULL, check=False)
    if result.returncode:
        fail("github_command_failed")
    return result.stdout


def release_state(tag, prerelease):
    try:
        data = json.loads(gh("release", "view", tag, "--repo", REPOSITORY,
                             "--json", "tagName,isDraft,isPrerelease,assets"))
    except (ValueError, TypeError):
        fail("invalid_github_release")
    if (data.get("tagName") != tag or data.get("isDraft") is not False or
            data.get("isPrerelease") is not prerelease or not isinstance(data.get("assets"), list)):
        fail("github_release_identity_mismatch")
    names = [item.get("name") for item in data["assets"] if isinstance(item, dict)]
    if len(names) != len(data["assets"]) or len(set(names)) != len(names):
        fail("invalid_github_assets")
    return set(names)


def verify_existing(tag, filename, expected):
    with tempfile.TemporaryDirectory(prefix="sp-release-asset-") as temporary:
        gh("release", "download", tag, "--repo", REPOSITORY,
           "--pattern", filename, "--dir", temporary)
        candidate = Path(temporary) / filename
        if not candidate.is_file() or candidate.is_symlink() or candidate.read_bytes() != expected:
            fail("existing_github_asset_mismatch")


def operate(args):
    source_metadata(args.source_sha, args.tag, args.prerelease)
    if Path(args.zip).name != ZIP_NAME or Path(args.manifest).name != MANIFEST_NAME:
        fail("release_asset_name_mismatch")
    inputs = argparse.Namespace(source_sha=args.source_sha, tag=args.tag,
                                prerelease=args.prerelease, zip=args.zip,
                                manifest=args.manifest,
                                expected_zip_sha256=args.expected_zip_sha256)
    with contextlib.redirect_stdout(io.StringIO()):
        package.validate(inputs)
    assets = {ZIP_NAME: Path(args.zip).read_bytes(),
              MANIFEST_NAME: Path(args.manifest).read_bytes()}
    if hashlib.sha256(assets[ZIP_NAME]).hexdigest() != args.expected_zip_sha256:
        fail("trusted_checksum_mismatch")
    existing = release_state(args.tag, args.prerelease)
    for filename, content in assets.items():
        if filename in existing:
            verify_existing(args.tag, filename, content)
    if args.operation == "preflight":
        print("PASS release GitHub asset preflight")
        return
    for filename, content in assets.items():
        current = release_state(args.tag, args.prerelease)
        if filename in current:
            verify_existing(args.tag, filename, content)
            continue
        gh("release", "upload", args.tag,
           args.zip if filename == ZIP_NAME else args.manifest, "--repo", REPOSITORY)
        verify_existing(args.tag, filename, content)
    print("PASS release GitHub assets exact")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("operation", choices=["preflight", "publish"])
    parser.add_argument("--source-sha", required=True)
    parser.add_argument("--tag", required=True)
    parser.add_argument("--zip", required=True)
    parser.add_argument("--manifest", required=True)
    parser.add_argument("--expected-zip-sha256", required=True)
    parser.add_argument("--prerelease", action="store_true")
    args = parser.parse_args()
    try:
        operate(args)
    except ReleaseError as error:
        print("FAIL release GitHub asset: " + str(error), file=sys.stderr)
        return 1
    except Exception:
        print("FAIL release GitHub asset: unexpected_error", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
