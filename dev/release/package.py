#!/usr/bin/env python3
"""Build and validate the Safety Passwords release ZIP from committed source."""

import argparse
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import unicodedata
import zipfile
from pathlib import Path

from source import ROOT, ReleaseError, git, source_metadata


SLUG = "safety-passwords"
ROOT_PREFIX = SLUG + "/"
ARCHIVE_NAME = "safety-passwords-wp-plugin.zip"
MANIFEST_NAME = "safety-passwords-wp-plugin.manifest.json"
REQUIRED = {
    "safety-passwords.php", "readme.txt", "vendor/autoload.php",
    "vendor/composer/autoload_real.php", "vendor/composer/installed.json",
    "vendor/htmlburger/carbon-fields/core/Carbon_Fields.php",
    "vendor/psr/log/Psr/Log/LoggerInterface.php",
}
SOURCE_ROOT_FILES = {"safety-passwords.php", "readme.txt", "composer.json", "composer.lock"}
VENDOR_PRUNE_DIRS = {"test", "tests", "doc", "docs", "example", "examples", "bin",
                     "packages", "node_modules", "coverage", "vendor-bin"}
VENDOR_SUFFIXES = {".php", ".js", ".css", ".json", ".mo", ".po", ".pot", ".txt",
                   ".svg", ".png", ".jpg", ".jpeg", ".gif", ".woff", ".woff2",
                   ".ttf", ".eot", ".html"}
BANNED_SUFFIXES = {".env", ".log", ".sql", ".db", ".sqlite", ".zip", ".tgz",
                   ".tar", ".gz", ".pem", ".key", ".crt", ".p12", ".pfx"}


def fail(category):
    raise ReleaseError(category)


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def clean_relative(path):
    if (not path or path.startswith("/") or "\\" in path or ":" in path or
            unicodedata.normalize("NFC", path) != path or
            any(ord(char) < 32 or ord(char) == 127 for char in path)):
        fail("unsafe_path")
    parts = path.split("/")
    if any(not part or part in {".", ".."} or part.startswith(".") for part in parts):
        fail("unsafe_path")
    if any("secret" in part.casefold() or "credential" in part.casefold() or
           "token" in part.casefold() for part in parts):
        fail("secret_like_path")
    if Path(path).suffix.casefold() in BANNED_SUFFIXES or path.casefold().endswith(".tar.gz"):
        fail("forbidden_file_type")
    return parts


def source_file_allowed(relative):
    parts = clean_relative(relative)
    if len(parts) == 1:
        return relative in SOURCE_ROOT_FILES
    if parts[0] == "src" and relative.endswith(".php"):
        return True
    if parts[0] == "assets" and Path(relative).suffix in {".css", ".js", ".png", ".svg"}:
        return True
    if parts[0] == "languages" and Path(relative).suffix in {".po", ".mo", ".pot"}:
        return True
    return False


def source_files(source_sha):
    entries = git("ls-tree", "-r", "-z", source_sha, "--", "plugin-dir/").split(b"\0")
    files = {}
    folded = set()
    for entry in entries:
        if not entry:
            continue
        try:
            descriptor, raw_path = entry.split(b"\t", 1)
            mode, kind, oid = descriptor.decode("ascii").split(" ")
            path = raw_path.decode("utf-8")
        except (ValueError, UnicodeDecodeError):
            fail("invalid_source_tree")
        if mode != "100644" or kind != "blob" or not path.startswith("plugin-dir/"):
            fail("unsafe_source_entry")
        relative = path[len("plugin-dir/"):]
        if not source_file_allowed(relative):
            fail("unapproved_plugin_source")
        folded_name = relative.casefold()
        if folded_name in folded:
            fail("case_colliding_source")
        folded.add(folded_name)
        files[relative] = git("cat-file", "blob", oid)
    if not SOURCE_ROOT_FILES.issubset(files):
        fail("missing_plugin_source")
    return files


def committed_epoch(source_sha):
    try:
        value = int(git("show", "-s", "--format=%ct", source_sha).strip())
    except ValueError:
        fail("invalid_source_epoch")
    if value < 315532800 or value > 4354819198:
        fail("invalid_source_epoch")
    return value - value % 2


def locked_packages(lock_bytes):
    try:
        lock = json.loads(lock_bytes)
        packages = lock["packages"]
        if lock.get("packages-dev"):
            fail("development_dependency_in_lock")
        versions = {item["name"]: item["version"] for item in packages}
    except (KeyError, TypeError, ValueError):
        fail("invalid_composer_lock")
    if set(versions) != {"htmlburger/carbon-fields", "psr/log"}:
        fail("unexpected_production_dependency")
    return versions


def verify_installed(stage, versions):
    try:
        installed = json.loads((stage / "vendor/composer/installed.json").read_bytes())
        packages = installed["packages"] if isinstance(installed, dict) else installed
        actual = {item["name"]: item["version"] for item in packages}
    except (OSError, KeyError, TypeError, ValueError):
        fail("missing_installed_dependency_identity")
    if actual != versions:
        fail("installed_dependency_mismatch")


def runtime_autoload(stage):
    expression = (
        "require $argv[1]; "
        "if (!class_exists('iTRON\\\\SafetyPasswords\\\\Controller') || "
        "!class_exists('Carbon_Fields\\\\Carbon_Fields') || "
        "!interface_exists('Psr\\\\Log\\\\LoggerInterface')) exit(1);"
    )
    result = subprocess.run(["php", "-r", expression, str(stage / "vendor/autoload.php")],
                            cwd=str(stage), stdout=subprocess.DEVNULL,
                            stderr=subprocess.DEVNULL, check=False)
    if result.returncode:
        fail("production_autoload_failed")


def prune_vendor(stage):
    vendor = stage / "vendor"
    if not vendor.is_dir() or vendor.is_symlink():
        fail("missing_production_vendor")
    for directory, child_dirs, child_files in os.walk(vendor, topdown=True, followlinks=False):
        current = Path(directory)
        for name in list(child_dirs):
            child = current / name
            if child.is_symlink():
                fail("vendor_symlink")
            if name.startswith(".") or name.casefold() in VENDOR_PRUNE_DIRS:
                shutil.rmtree(child)
                child_dirs.remove(name)
        for name in child_files:
            child = current / name
            if child.is_symlink() or not child.is_file():
                fail("vendor_special_file")
            relative = child.relative_to(stage).as_posix()
            parts = clean_relative(relative) if not name.startswith(".") else None
            suffix = child.suffix.casefold()
            if (parts is None or name.casefold() in {"composer.json", "composer.lock",
                                                   "package.json", "package-lock.json", "readme.md"}
                    or suffix not in VENDOR_SUFFIXES and name.casefold() not in
                    {"license", "copying", "notice"}):
                child.unlink()
    for directory, child_dirs, child_files in os.walk(vendor, topdown=False):
        if not child_dirs and not child_files:
            Path(directory).rmdir()


def packaged_files(stage):
    output = {}
    folded = set()
    for path in stage.rglob("*"):
        if path.is_symlink():
            fail("package_symlink")
        if path.is_dir():
            continue
        if not path.is_file():
            fail("package_special_file")
        relative = path.relative_to(stage).as_posix()
        parts = clean_relative(relative)
        if relative in {"composer.json", "composer.lock"}:
            fail("build_manifest_in_package")
        if parts[0] != "vendor" and not source_file_allowed(relative):
            fail("unexpected_package_file")
        if parts[0] == "vendor" and not vendor_file_allowed(parts):
            fail("invalid_vendor_file")
        folded_name = relative.casefold()
        if folded_name in folded:
            fail("case_colliding_package")
        folded.add(folded_name)
        output[relative] = path.read_bytes()
    if not REQUIRED.issubset(output):
        fail("missing_runtime_file")
    return output


def vendor_file_allowed(parts):
    if parts == ["vendor", "autoload.php"]:
        return True
    if len(parts) < 3 or parts[0] != "vendor":
        return False
    if parts[1] == "composer":
        known_package = len(parts) == 3
    else:
        known_package = len(parts) >= 4 and "/".join(parts[1:3]) in {
            "htmlburger/carbon-fields", "psr/log"}
    return known_package and (Path(parts[-1]).suffix.casefold() in VENDOR_SUFFIXES or
                              parts[-1].casefold() in {"license", "copying", "notice"})


def zip_bytes(files, epoch):
    import io
    memory = io.BytesIO()
    timestamp = time.gmtime(epoch)[:6]
    with zipfile.ZipFile(memory, "w", compression=zipfile.ZIP_DEFLATED,
                         compresslevel=9, allowZip64=True) as archive:
        for relative in sorted(files, key=lambda name: name.encode("utf-8")):
            info = zipfile.ZipInfo(ROOT_PREFIX + relative, timestamp)
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, files[relative], compress_type=zipfile.ZIP_DEFLATED,
                             compresslevel=9)
    return memory.getvalue()


def output_directory(raw):
    path = Path(raw)
    if not path.is_absolute() or not path.is_dir() or path.is_symlink():
        fail("invalid_output_directory")
    resolved = path.resolve()
    if Path("/tmp") not in resolved.parents:
        fail("output_must_be_disposable_tmp")
    return resolved


def build(args):
    version = source_metadata(args.source_sha, args.tag, args.prerelease)
    source = source_files(args.source_sha)
    versions = locked_packages(source["composer.lock"])
    target = output_directory(args.output_dir)
    archive_path = target / ARCHIVE_NAME
    manifest_path = target / MANIFEST_NAME
    if archive_path.exists() or archive_path.is_symlink() or manifest_path.exists() or manifest_path.is_symlink():
        fail("output_already_exists")
    with tempfile.TemporaryDirectory(prefix="sp-release-stage-") as temporary:
        stage = Path(temporary) / SLUG
        stage.mkdir()
        for relative, content in source.items():
            destination = stage / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(content)
        # Composer otherwise generates a random autoloader class name for each
        # fresh vendor directory. Set it only in the disposable build manifest;
        # the committed composer.json and composer.lock remain unchanged.
        try:
            composer_manifest = json.loads((stage / "composer.json").read_text(encoding="utf-8"))
            composer_config = composer_manifest.setdefault("config", {})
            if not isinstance(composer_config, dict):
                fail("invalid_composer_config")
        except (OSError, ValueError, TypeError):
            fail("invalid_composer_config")
        composer_config["autoloader-suffix"] = "SafetyPasswordsRelease" + args.source_sha[:20]
        (stage / "composer.json").write_text(json.dumps(composer_manifest), encoding="utf-8")
        environment = {key: value for key, value in os.environ.items()
                       if not key.startswith("COMPOSER_")}
        environment.update(COMPOSER_HOME=str(Path(temporary) / "composer-home"),
                           COMPOSER_NO_INTERACTION="1", COMPOSER_DISABLE_XDEBUG_WARN="1")
        result = subprocess.run(
            ["composer", "install", "--no-dev", "--no-scripts", "--no-plugins",
             "--prefer-dist", "--no-interaction", "--no-progress", "--optimize-autoloader"],
            cwd=str(stage), env=environment, stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL, check=False)
        if result.returncode:
            fail("production_composer_install_failed")
        verify_installed(stage, versions)
        runtime_autoload(stage)
        prune_vendor(stage)
        (stage / "composer.json").unlink()
        (stage / "composer.lock").unlink()
        files = packaged_files(stage)
        archive_data = zip_bytes(files, committed_epoch(args.source_sha))
        manifest = {
            "schema": 1, "source_sha": args.source_sha, "version": version,
            "tag": args.tag or "", "prerelease": args.prerelease,
            "source_epoch": committed_epoch(args.source_sha),
            "lock_sha256": sha256(source["composer.lock"]),
            "zip_sha256": sha256(archive_data),
            "files": {name: sha256(content) for name, content in sorted(files.items())},
        }
        archive_path.write_bytes(archive_data)
        manifest_path.write_text(json.dumps(manifest, sort_keys=True, separators=(",", ":")) + "\n",
                                 encoding="utf-8")
    print("PASS release package build")


def validated_archive(archive_path, epoch):
    try:
        if not archive_path.is_file() or archive_path.is_symlink() or archive_path.stat().st_size > 150000000:
            fail("invalid_archive_file")
        archive = zipfile.ZipFile(archive_path)
    except (OSError, zipfile.BadZipFile):
        fail("invalid_archive_file")
    files = {}
    seen = set()
    timestamp = time.gmtime(epoch)[:6]
    with archive:
        if archive.comment or len(archive.infolist()) > 50000:
            fail("unsafe_archive_metadata")
        if sum(item.file_size for item in archive.infolist()) > 200000000:
            fail("oversized_archive")
        for info in archive.infolist():
            name = info.filename
            if getattr(info, "orig_filename", name) != name or not name.startswith(ROOT_PREFIX) or name.endswith("/"):
                fail("unsafe_archive_root")
            relative = name[len(ROOT_PREFIX):]
            parts = clean_relative(relative)
            folded = relative.casefold()
            if folded in seen:
                fail("duplicate_archive_entry")
            seen.add(folded)
            if (info.create_system != 3 or stat.S_IFMT(info.external_attr >> 16) != stat.S_IFREG
                    or stat.S_IMODE(info.external_attr >> 16) != 0o644 or info.extra
                    or info.comment or info.flag_bits & 1 or info.date_time != timestamp
                    or info.compress_type != zipfile.ZIP_DEFLATED):
                fail("unsafe_archive_metadata")
            if info.file_size > 25000000:
                fail("oversized_archive")
            if parts[0] != "vendor" and not source_file_allowed(relative):
                fail("unexpected_archive_file")
            if parts[0] == "vendor" and (not vendor_file_allowed(parts) or
                    any(part.casefold() in VENDOR_PRUNE_DIRS for part in parts[1:]) or
                    parts[-1].casefold() in {"composer.json", "composer.lock", "package.json",
                                               "package-lock.json", "readme.md"}):
                fail("development_archive_file")
            files[relative] = archive.read(info)
    if not REQUIRED.issubset(files):
        fail("missing_runtime_file")
    return files


def validate(args):
    version = source_metadata(args.source_sha, args.tag, args.prerelease)
    source = source_files(args.source_sha)
    versions = locked_packages(source["composer.lock"])
    try:
        manifest_data = json.loads(Path(args.manifest).read_text(encoding="utf-8"))
        archive_data = Path(args.zip).read_bytes()
    except (OSError, ValueError):
        fail("missing_release_artifact")
    if not isinstance(manifest_data, dict) or Path(args.manifest).is_symlink():
        fail("invalid_manifest_file")
    if (manifest_data.get("schema") != 1 or manifest_data.get("source_sha") != args.source_sha
            or manifest_data.get("version") != version or manifest_data.get("tag") != (args.tag or "")
            or manifest_data.get("prerelease") is not args.prerelease
            or manifest_data.get("source_epoch") != committed_epoch(args.source_sha)
            or manifest_data.get("lock_sha256") != sha256(source["composer.lock"])):
        fail("manifest_source_mismatch")
    digest = sha256(archive_data)
    if manifest_data.get("zip_sha256") != digest:
        fail("manifest_checksum_mismatch")
    if args.expected_zip_sha256 and args.expected_zip_sha256 != digest:
        fail("trusted_checksum_mismatch")
    files = validated_archive(Path(args.zip), committed_epoch(args.source_sha))
    if manifest_data.get("files") != {name: sha256(content) for name, content in files.items()}:
        fail("manifest_files_mismatch")
    expected_source = {name: content for name, content in source.items()
                       if name not in {"composer.json", "composer.lock"}}
    if any(files.get(name) != content for name, content in expected_source.items()):
        fail("archive_source_mismatch")
    if set(files) - set(expected_source) != {name for name in files if name.startswith("vendor/")}:
        fail("unexpected_archive_file")
    with tempfile.TemporaryDirectory(prefix="sp-release-validate-") as temporary:
        stage = Path(temporary)
        for relative, content in files.items():
            destination = stage / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(content)
        verify_installed(stage, versions)
        runtime_autoload(stage)
    print("PASS release package validation")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="action", required=True)
    build_parser = subparsers.add_parser("build")
    build_parser.add_argument("--source-sha", required=True)
    build_parser.add_argument("--output-dir", required=True)
    build_parser.add_argument("--tag")
    build_parser.add_argument("--prerelease", action="store_true")
    validate_parser = subparsers.add_parser("validate")
    validate_parser.add_argument("--source-sha", required=True)
    validate_parser.add_argument("--zip", required=True)
    validate_parser.add_argument("--manifest", required=True)
    validate_parser.add_argument("--expected-zip-sha256")
    validate_parser.add_argument("--tag")
    validate_parser.add_argument("--prerelease", action="store_true")
    args = parser.parse_args()
    try:
        if args.action == "build":
            build(args)
        else:
            validate(args)
    except ReleaseError as error:
        print("FAIL release package: " + str(error), file=sys.stderr)
        return 1
    except Exception:
        print("FAIL release package: unexpected_error", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
