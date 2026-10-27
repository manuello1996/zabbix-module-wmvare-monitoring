#!/usr/bin/env python3
"""Synchronize common sources and publish a self-contained Zabbix module locally.

Only Python's standard library and Git are required. GitHub CLI is optional.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
import subprocess
import sys
import zipfile
from pathlib import Path

GENERATED = {
    "templates/Formatting.php.in": "includes/common/Formatting.php",
    "templates/LocationHierarchy.php.in": "includes/common/LocationHierarchy.php",
    "templates/LocationRows.php.in": "includes/common/LocationRows.php",
    "templates/SearchControl.php.in": "includes/common/SearchControl.php",
    "templates/Summary.php.in": "includes/common/Summary.php",
    "templates/Breadcrumbs.php.in": "includes/common/Breadcrumbs.php",
    "templates/ProblemBadges.php.in": "includes/common/ProblemBadges.php",
    "templates/CapacityBar.php.in": "includes/common/CapacityBar.php",
    "templates/SortableHeader.php.in": "includes/common/SortableHeader.php",
    "templates/Sparkline.php.in": "includes/common/Sparkline.php",
    "templates/ItemActions.php.in": "includes/common/ItemActions.php",
    "templates/Pager.php.in": "includes/common/Pager.php",
    "templates/RowDetails.php.in": "includes/common/RowDetails.php",
    "templates/AutoRefresh.php.in": "includes/common/AutoRefresh.php",
    "templates/bootstrap.php.in": "includes/common/bootstrap.php",
    "templates/common.css.in": "assets/css/common.css",
    "templates/common.js.in": "assets/js/common.js",
    "templates/components.css.in": "assets/css/components.css",
    "templates/components.js.in": "assets/js/components.js",
    "tools/release.py": "tools/release.py",
    "tools/release.ps1": "release.ps1",
}
RUNTIME_DIRS = {"actions", "assets", "includes", "services", "views", "templates", "agent2", "img"}
RUNTIME_FILES = {"manifest.json", "Module.php", "Widget.php", "README.md", "common.lock.json", "LICENSE", "COPYING"}
DEVELOPMENT_PARTS = {"dev", "test", "tests", "mock", "mocks", "fixtures", "__pycache__", "node_modules",
                     ".git", ".github", ".idea", ".vscode", ".tools", "dist"}


class ReleaseError(Exception):
    pass


def run(root, *args, check=True):
    result = subprocess.run(args, cwd=root, capture_output=True, text=True, encoding="utf-8")
    if check and result.returncode:
        raise ReleaseError(f"{' '.join(args)}\n{result.stderr.strip() or result.stdout.strip()}")
    return result


def git(root, *args, check=True):
    return run(root, "git", *args, check=check)


def read_json(path):
    return json.loads(path.read_text(encoding="utf-8-sig"))


def write_json(path, value):
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def digest(data):
    return hashlib.sha256(data).hexdigest()


def safe_path(root, relative):
    rel = Path(relative)
    if rel.is_absolute() or ".." in rel.parts:
        raise ReleaseError(f"Unsafe module path: {relative}")
    path = root / rel
    for parent in [path, *path.parents]:
        if parent == root:
            break
        if parent.is_symlink() or (hasattr(parent, "is_junction") and parent.is_junction()):
            raise ReleaseError(f"Linked module paths are not supported: {relative}")
    if not path.resolve().is_relative_to(root.resolve()):
        raise ReleaseError(f"Module path escapes the repository: {relative}")
    return path


def source_state(source):
    top = git(source, "rev-parse", "--show-toplevel", check=False)
    if top.returncode or Path(top.stdout.strip()).resolve() != source.resolve():
        return None, True
    revision = git(source, "rev-parse", "HEAD", check=False)
    return (revision.stdout.strip() if revision.returncode == 0 else None,
            bool(git(source, "status", "--porcelain").stdout))


def synchronize(root, source, config, manifest):
    metadata = read_json(source / "common.json")
    if metadata.get("name") != "zabbix-module-common" or metadata.get("schema") != 1:
        raise ReleaseError("Unsupported common source repository.")
    replacements = {"MODULE_NAMESPACE": manifest["namespace"], **config["tokens"]}
    outputs = {}
    source_hash = hashlib.sha256()
    for relative in ["common.json", *GENERATED]:
        data = safe_path(source, relative).read_bytes().replace(b"\r\n", b"\n")
        source_hash.update(relative.encode() + b"\0" + data + b"\0")
        if relative not in GENERATED:
            continue
        text = data.decode("utf-8")
        if relative.startswith("templates/"):
            for name, value in replacements.items():
                text = text.replace(f"__{name}__", value)
            unresolved = set(re.findall(r"__[A-Z_]+__", text))
            if relative.endswith(".php.in"):
                unresolved -= {"__DIR__", "__FILE__", "__NAMESPACE__", "__CLASS__", "__TRAIT__",
                               "__FUNCTION__", "__METHOD__", "__LINE__"}
            if unresolved:
                raise ReleaseError(f"Unresolved template token in {relative}.")
        outputs[GENERATED[relative]] = text.encode("utf-8")
    revision, dirty = source_state(source)
    lock = {"name": metadata["name"], "version": metadata["version"],
            "source_commit": revision, "source_dirty": dirty,
            "source_sha256": source_hash.hexdigest(),
            "files": {name: digest(data) for name, data in outputs.items()}}
    for relative, data in outputs.items():
        path = safe_path(root, relative)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
    write_json(root / "common.lock.json", lock)
    print(f"Bundled common {lock['version']} ({lock['source_sha256'][:12]}).")


def verify_bundle(root):
    lock = read_json(root / "common.lock.json")
    if set(lock["files"]) != set(GENERATED.values()):
        raise ReleaseError("Incomplete common bundle. Run release.ps1 -SyncOnly.")
    for relative, expected in lock["files"].items():
        if digest(safe_path(root, relative).read_bytes()) != expected:
            raise ReleaseError(f"Generated file was changed: {relative}. Synchronize from common.")
    return lock


def validate_module(root, manifest):
    entrypoint = "Widget.php" if manifest.get("type") == "widget" else "Module.php"
    needed = [entrypoint, *(name for name in GENERATED.values() if name.startswith(("includes/", "assets/")))]
    for kind in ["css", "js"]:
        needed.extend(f"assets/{kind}/{name}" for name in manifest.get("assets", {}).get(kind, []))
    for action in manifest.get("actions", {}).values():
        needed.append(f"actions/{action['class']}.php")
        if action.get("view"):
            needed.append(f"views/{action['view']}.php")
    for relative in needed:
        if not safe_path(root, relative).is_file():
            raise ReleaseError(f"Required module file is missing: {relative}")
    if "common.css" not in manifest.get("assets", {}).get("css", []):
        raise ReleaseError("manifest.json must register common.css.")
    scripts = manifest.get("assets", {}).get("js", [])
    if not scripts or scripts[0] != "common.js":
        raise ReleaseError("manifest.json must register common.js before module JavaScript.")
    if len(scripts) < 2 or scripts[1] != "components.js":
        raise ReleaseError("manifest.json must register components.js immediately after common.js.")
    if manifest.get("assets", {}).get("css", [])[:2] != ["common.css", "components.css"]:
        raise ReleaseError("manifest.json must register common.css and components.css before module styles.")


def package(root, manifest):
    names = set(git(root, "ls-files", "--cached", "--others", "--exclude-standard", "-z").stdout.split("\0"))
    names.discard("")
    files = []
    for name in sorted(names):
        path = Path(name)
        if any(part.lower() in DEVELOPMENT_PARTS for part in path.parts):
            continue
        if path.name.lower().startswith(".env") or path.suffix.lower() in {".pyc", ".pyo", ".log"}:
            continue
        runtime = (path.parts[0] in RUNTIME_DIRS or name in RUNTIME_FILES
                   or (len(path.parts) == 1 and path.suffix.lower() in {".yaml", ".yml"}))
        if runtime:
            target = safe_path(root, name)
            if target.is_file():
                files.append((name, target))
    entrypoint = "Widget.php" if manifest.get("type") == "widget" else "Module.php"
    for name in ["manifest.json", entrypoint, "common.lock.json",
                 *(name for name in GENERATED.values() if name.startswith(("includes/", "assets/")))]:
        if name not in {relative for relative, _ in files}:
            raise ReleaseError(f"Required runtime file is ignored or absent: {name}")
    out = safe_path(root, "dist")
    out.mkdir(exist_ok=True)
    archive = out / f"{manifest['id']}-{manifest['version']}.zip"
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED) as bundle:
        for name, path in files:
            entry = zipfile.ZipInfo(f"{manifest['id']}/{name}", date_time=(2020, 1, 1, 0, 0, 0))
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            bundle.writestr(entry, path.read_bytes())
    print(f"Standalone ZIP: {archive}")
    return archive


def update_version(root, version):
    path = root / "manifest.json"
    text = path.read_text(encoding="utf-8-sig")
    text, count = re.subn(r'("version"\s*:\s*")[^"]+("\s*[,}])',
                         lambda match: match[1] + version + match[2], text, count=1)
    if count != 1:
        raise ReleaseError("Cannot update manifest version.")
    path.write_text(text, encoding="utf-8")


def publish(root, branch, tag, archive, github_release, retry):
    if not retry:
        git(root, "add", "--all")
        if git(root, "diff", "--cached", "--quiet", check=False).returncode:
            git(root, "commit", "-m", f"Release {tag} with bundled common components")
        git(root, "tag", "-a", tag, "-m", f"Standalone module {tag}")
    print(f"Pushing {branch} and {tag} to origin.")
    git(root, "push", "--atomic", "origin", f"HEAD:refs/heads/{branch}", f"refs/tags/{tag}")
    if github_release:
        remote = git(root, "remote", "get-url", "--push", "origin").stdout.strip()
        result = run(root, "gh", "release", "view", tag, "--repo", remote,
                     "--json", "assets", "--jq", ".assets[].name", check=False)
        if result.returncode == 0:
            # Also covers retrying after Git succeeded but creating/uploading the release failed.
            if archive.name not in result.stdout.splitlines():
                run(root, "gh", "release", "upload", tag, str(archive), "--repo", remote)
        else:
            run(root, "gh", "release", "create", tag, str(archive), "--repo", remote,
                "--verify-tag", "--title", tag, "--generate-notes")
    print(f"Published {tag}. Each module checkout and ZIP includes its common runtime files.")


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("version", nargs="?", help="Module version, e.g. 1.2.1")
    parser.add_argument("--module-root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--common-path", type=Path)
    parser.add_argument("--local-only", action="store_true", help="Sync/build without committing, tagging or pushing")
    parser.add_argument("--sync-only", action="store_true", help="Only refresh the bundled common files")
    parser.add_argument("--bundled", action="store_true", help="Use the existing pinned bundle instead of local common")
    parser.add_argument("--github-release", action="store_true", help="Also attach the ZIP to a GitHub Release using gh")
    args = parser.parse_args(argv)
    if (not args.sync_only and not args.version) or (args.version and not re.fullmatch(r"\d+\.\d+(?:\.\d+)?", args.version)):
        parser.error("Provide a numeric module version, e.g. 1.2.1, or --sync-only.")
    if args.github_release and (args.local_only or args.sync_only):
        parser.error("--github-release requires publishing.")
    if args.bundled and args.common_path:
        parser.error("--bundled cannot be combined with --common-path.")
    root = args.module_root.resolve()
    if Path(git(root, "rev-parse", "--show-toplevel").stdout.strip()).resolve() != root:
        raise ReleaseError("Run this against the module's own Git repository.")
    config = read_json(root / "common.config.json")
    manifest = read_json(root / "manifest.json")
    if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", manifest.get("namespace", "")):
        raise ReleaseError("Unsupported module namespace.")
    if not re.fullmatch(r"[a-zA-Z0-9_-]+", manifest.get("id", "")):
        raise ReleaseError("Unsupported module ID.")
    if args.version:
        numeric = lambda value: tuple((list(map(int, value.split('.'))) + [0, 0, 0])[:3])
        if numeric(args.version) < numeric(manifest["version"]):
            raise ReleaseError("The release version cannot be older than the current manifest version.")
    publishing = not (args.local_only or args.sync_only)
    source = (args.common_path or root.parent / config["source_directory"]).resolve()
    branch = git(root, "branch", "--show-current").stdout.strip()
    tag = f"v{args.version}"
    retry = False
    if publishing:
        if not branch:
            raise ReleaseError("Check out a branch before publishing.")
        git(root, "remote", "get-url", "--push", "origin")
        if git(root, "ls-files", "dist").stdout:
            raise ReleaseError("dist/ must be ignored and untracked.")
        if args.github_release:
            if not shutil.which("gh"):
                raise ReleaseError("Install GitHub CLI and run gh auth login, or omit --github-release.")
            run(root, "gh", "auth", "status")
        existing = git(root, "rev-parse", "--verify", f"refs/tags/{tag}^{{}}", check=False)
        if existing.returncode == 0:
            retry = (existing.stdout.strip() == git(root, "rev-parse", "HEAD").stdout.strip()
                     and manifest["version"] == args.version and not git(root, "status", "--porcelain").stdout)
            if not retry:
                raise ReleaseError(f"Tag {tag} already exists. Choose a new version or finish the existing release.")
        remote = git(root, "ls-remote", "origin", f"refs/tags/{tag}", f"refs/tags/{tag}^{{}}")
        if remote.stdout and (not retry or existing.stdout.strip() not in remote.stdout.split()):
            raise ReleaseError(f"Tag {tag} already exists on origin.")
        if not retry and not args.bundled and source.is_dir():
            revision, dirty = source_state(source)
            if dirty or not revision:
                raise ReleaseError("Commit changes in zabbix-module-common before publishing. Use --local-only to preview.")
    if not retry and not args.bundled and source.is_dir():
        synchronize(root, source, config, manifest)
    elif args.common_path and not source.is_dir():
        raise ReleaseError(f"Common source directory not found: {source}")
    else:
        print("Using the existing pinned common bundle.")
    lock = verify_bundle(root)
    if publishing and lock.get("source_dirty"):
        raise ReleaseError("The bundle came from uncommitted common sources. Synchronize a committed common revision.")
    validate_module(root, manifest)
    if args.sync_only:
        print("Synchronized. Module version and Git history are unchanged.")
        return
    if not retry:
        update_version(root, args.version)
        manifest["version"] = args.version
    archive = package(root, manifest)
    if args.local_only:
        print("Local build complete. No commit, tag or push was made.")
    else:
        publish(root, branch, tag, archive, args.github_release, retry)


if __name__ == "__main__":
    try:
        main()
    except (ReleaseError, OSError, ValueError, KeyError) as error:
        print(f"Release failed: {error}", file=sys.stderr)
        print("If a local release tag was already created, fix the error and rerun the same command.", file=sys.stderr)
        sys.exit(1)
