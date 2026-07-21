#!/usr/bin/env python3

import re
import sys


SENSITIVE_KEY = r'(?:password|passwd|secret|token|credential|app[ _-]?key|private[ _-]?key)'
CREDENTIAL_URL = re.compile(r'([a-z][a-z0-9+.-]*://[^\s/:@]+:)[^\s@]+@', re.IGNORECASE)
AUTHORIZATION_HEADER = re.compile(
    r'(\bauthorization\s*:\s*)(?:bearer|basic)\s+[^\s,;]+',
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
    inside_private_key = False
    with open(source, 'r', encoding='utf-8', errors='replace') as input_file, open(
        destination,
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


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit('usage: sanitize-evidence.py SOURCE DESTINATION')
    sanitize(sys.argv[1], sys.argv[2])
