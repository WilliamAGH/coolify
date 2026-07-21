#!/usr/bin/env python3

import os
import re
import shutil
import stat
import sys
import tempfile
from pathlib import Path


SENSITIVE_KEY = r'(?:password|passwd|secret|token|credential|app[ _-]?key|private[ _-]?key)'
CREDENTIAL_URL = re.compile(r'([a-z][a-z0-9+.-]*://[^\s/:@]+:)[^\s@]+@', re.IGNORECASE)
AUTHORIZATION_HEADER = re.compile(
    r'^([ \t>]*authorization\s*:\s*)[^\r\n]+$',
    re.IGNORECASE,
)
COOKIE_HEADER = re.compile(r'(\b(?:set-)?cookie\s*:\s*)[^\r\n]+', re.IGNORECASE)
QUOTED_ASSIGNMENT = re.compile(
    rf'(?P<prefix>["\']?\b(?P<key>{SENSITIVE_KEY})\b["\']?\s*[:=]\s*)'
    rf'(?P<quote>["\'])(?P<value>.*?)(?P=quote)',
    re.IGNORECASE,
)
UNQUOTED_ASSIGNMENT = re.compile(
    rf'(?P<prefix>\b(?P<key>{SENSITIVE_KEY})\b\s*[:=]\s*)(?P<value>[^\s,;]+)',
    re.IGNORECASE,
)
SENSITIVE_OPTION = re.compile(
    r'(?P<prefix>--(?:password|passwd|secret|token|credential|app[ _-]?key)(?:=|\s+))'
    r'(?P<value>[^\s,;]+)',
    re.IGNORECASE,
)
PRIVATE_KEY_BEGIN = re.compile(r'^-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----\s*$')
PRIVATE_KEY_END = re.compile(r'^-----END [A-Z0-9 ]*PRIVATE KEY-----\s*$')
EXCLUDED_TREE_ARTIFACTS = frozenset({'images.tar'})


class SanitizationError(RuntimeError):
    pass


def redact_assignment(match: re.Match[str]) -> str:
    key = re.sub(r'[^a-z0-9]+', '-', match.group('key').lower()).strip('-')
    marker = f'[REDACTED sensitive-value:{key}]'
    quote = match.groupdict().get('quote', '')

    return f'{match.group("prefix")}{quote}{marker}{quote}'


def redact_line(line: str) -> str:
    line = CREDENTIAL_URL.sub(r'\1[REDACTED credential-url-password]@', line)
    line = AUTHORIZATION_HEADER.sub(r'\1[REDACTED authorization-header]', line)
    line = COOKIE_HEADER.sub(r'\1[REDACTED cookie-value]', line)
    line = QUOTED_ASSIGNMENT.sub(redact_assignment, line)
    line = UNQUOTED_ASSIGNMENT.sub(redact_assignment, line)
    line = SENSITIVE_OPTION.sub(r'\g<prefix>[REDACTED command-option-value]', line)

    return line


def sanitize(source: str, destination: str) -> None:
    source_path = Path(source)
    destination_path = Path(destination)
    validate_file_paths(source_path, destination_path)

    inside_private_key = False
    try:
        with source_path.open('r', encoding='utf-8', errors='replace') as input_file, destination_path.open(
            'w',
            encoding='utf-8',
        ) as output_file:
            for line in input_file:
                if PRIVATE_KEY_BEGIN.match(line):
                    output_file.write('[REDACTED private-key-material]\n')
                    inside_private_key = True
                    continue
                if inside_private_key:
                    if PRIVATE_KEY_END.match(line):
                        inside_private_key = False
                    continue
                output_file.write(redact_line(line))
    except OSError as error:
        raise SanitizationError(f'cannot sanitize source artifact {source}: {error}') from error


def validate_file_paths(source: Path, destination: Path) -> None:
    try:
        source_status = source.lstat()
    except OSError as error:
        raise SanitizationError(f'cannot inspect source file {source}: {error}') from error

    if stat.S_ISLNK(source_status.st_mode):
        raise SanitizationError(f'source file must not be a symlink: {source}')
    if not stat.S_ISREG(source_status.st_mode):
        raise SanitizationError(f'source must be a regular file: {source}')

    if destination.is_symlink():
        raise SanitizationError(f'destination file must not be a symlink: {destination}')

    try:
        if source.resolve(strict=True) == destination.resolve(strict=False):
            raise SanitizationError('source and destination must be different paths')
    except OSError as error:
        raise SanitizationError(f'cannot resolve evidence paths: {error}') from error


def sanitize_tree(source: str, destination: str) -> None:
    source_path = Path(source)
    destination_path = Path(destination)
    source_root, destination_root = validate_tree_paths(source_path, destination_path)
    source_files = tree_regular_files(source_root)
    staging_directory = Path(
        tempfile.mkdtemp(
            prefix=f'.{destination_path.name}.sanitizing-',
            dir=destination_path.parent,
        )
    )

    try:
        for source_file in source_files:
            relative_path = source_file.relative_to(source_root)
            if relative_path.is_absolute() or '..' in relative_path.parts:
                raise SanitizationError(f'unsafe source tree path: {source_file}')
            if not is_text_artifact(source_file):
                continue

            destination_file = staging_directory / relative_path
            destination_file.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            sanitize(str(source_file), str(destination_file))
            destination_file.chmod(0o600)

        if destination_path.exists():
            raise SanitizationError(f'destination appeared during sanitization: {destination_path}')
        staging_directory.rename(destination_root)
    except OSError as error:
        raise SanitizationError(f'cannot sanitize evidence tree: {error}') from error
    finally:
        if staging_directory.exists():
            shutil.rmtree(staging_directory)


def validate_tree_paths(source: Path, destination: Path) -> tuple[Path, Path]:
    try:
        source_status = source.lstat()
    except OSError as error:
        raise SanitizationError(f'cannot inspect source tree {source}: {error}') from error

    if stat.S_ISLNK(source_status.st_mode):
        raise SanitizationError(f'source tree must not be a symlink: {source}')
    if not stat.S_ISDIR(source_status.st_mode):
        raise SanitizationError(f'source must be a directory: {source}')

    if destination.exists():
        raise SanitizationError(f'destination tree must not already exist: {destination}')
    if destination.is_symlink():
        raise SanitizationError(f'destination tree must not be a symlink: {destination}')
    if destination.parent.is_symlink():
        raise SanitizationError(f'destination parent must not be a symlink: {destination.parent}')
    if not destination.parent.is_dir():
        raise SanitizationError(f'destination parent must exist: {destination.parent}')

    try:
        source_root = source.resolve(strict=True)
        destination_root = destination.resolve(strict=False)
    except OSError as error:
        raise SanitizationError(f'cannot resolve evidence tree paths: {error}') from error

    if is_within(destination_root, source_root) or is_within(source_root, destination_root):
        raise SanitizationError('source and destination trees must not contain one another')

    return source_root, destination_root


def tree_regular_files(source_root: Path) -> list[Path]:
    source_files: list[Path] = []

    def fail_walk(error: OSError) -> None:
        raise SanitizationError(f'cannot traverse source tree: {error}') from error

    try:
        for current_root, directory_names, file_names in os.walk(
            source_root,
            topdown=True,
            followlinks=False,
            onerror=fail_walk,
        ):
            current_path = Path(current_root)
            for name in sorted(directory_names):
                directory = current_path / name
                directory_status = directory.lstat()
                if stat.S_ISLNK(directory_status.st_mode):
                    raise SanitizationError(f'source tree contains a symlink: {directory}')
                if not stat.S_ISDIR(directory_status.st_mode):
                    raise SanitizationError(f'source tree contains an unsafe directory entry: {directory}')

            for name in sorted(file_names):
                source_file = current_path / name
                source_status = source_file.lstat()
                if stat.S_ISLNK(source_status.st_mode):
                    raise SanitizationError(f'source tree contains a symlink: {source_file}')
                if not stat.S_ISREG(source_status.st_mode):
                    raise SanitizationError(f'source tree contains an unsafe file entry: {source_file}')
                if source_file.name not in EXCLUDED_TREE_ARTIFACTS:
                    source_files.append(source_file)
    except OSError as error:
        raise SanitizationError(f'cannot inspect source tree: {error}') from error

    return source_files


def is_text_artifact(source: Path) -> bool:
    try:
        with source.open('rb') as source_file:
            while chunk := source_file.read(65536):
                if b'\0' in chunk:
                    return False
    except OSError as error:
        raise SanitizationError(f'cannot read source artifact {source}: {error}') from error

    return True


def is_within(path: Path, parent: Path) -> bool:
    try:
        path.relative_to(parent)
    except ValueError:
        return False

    return True


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit('usage: sanitize-evidence.py SOURCE DESTINATION')
    try:
        if Path(sys.argv[1]).is_dir():
            sanitize_tree(sys.argv[1], sys.argv[2])
        else:
            sanitize(sys.argv[1], sys.argv[2])
    except SanitizationError as error:
        raise SystemExit(f'evidence sanitization failed: {error}') from error
