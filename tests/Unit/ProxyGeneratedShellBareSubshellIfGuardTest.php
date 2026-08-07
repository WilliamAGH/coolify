<?php

/** @return list<string> */
function proxyGeneratedShellOwnersForBareSubshellIfGuard(): array
{
    $actionsRoot = dirname(__DIR__, 2).'/app/Actions/';
    $ownerRoots = [
        $actionsRoot.'Proxy',
        $actionsRoot.'Application/BlueGreen',
    ];
    $owners = [];

    foreach ($ownerRoots as $ownerRoot) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($ownerRoot, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $owners[] = $file->getPathname();
        }
    }

    sort($owners, SORT_STRING);

    return $owners;
}

/** @param array{0: int, 1: string, 2: int} $token */
function proxyGeneratedShellLiteralForBareSubshellIfGuard(array $token): ?string
{
    if ($token[0] === T_ENCAPSED_AND_WHITESPACE) {
        return stripcslashes($token[1]);
    }

    if ($token[0] !== T_CONSTANT_ENCAPSED_STRING || strlen($token[1]) < 2) {
        return null;
    }

    $quote = $token[1][0];
    $literal = substr($token[1], 1, -1);

    if ($quote === "'") {
        return str_replace(['\\\\', "\\'"], ['\\', "'"], $literal);
    }

    return stripcslashes($literal);
}

function proxyGeneratedShellExecutableTextForBareSubshellIfGuard(string $literal): string
{
    $executable = '';
    $comment = false;
    $quote = null;
    $length = strlen($literal);

    for ($index = 0; $index < $length; $index++) {
        $character = $literal[$index];

        if ($comment) {
            if ($character === "\n") {
                $comment = false;
                $executable .= "\n";
            } else {
                $executable .= ' ';
            }

            continue;
        }

        if ($quote === null) {
            $previousCharacter = $index === 0 ? "\n" : $literal[$index - 1];
            if ($character === '#' && str_contains(" \t\r\n;|&()", $previousCharacter)) {
                $comment = true;
                $executable .= ' ';

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
                $executable .= ' ';

                continue;
            }

            if ($character === '\\' && $index + 1 < $length) {
                $executable .= '  ';
                $index++;

                continue;
            }

            $executable .= $character;

            continue;
        }

        if ($character === "\n") {
            $executable .= "\n";

            continue;
        }

        if ($quote === '"' && $character === '\\' && $index + 1 < $length) {
            $executable .= '  ';
            $index++;

            continue;
        }

        if ($character === $quote) {
            $quote = null;
        }

        $executable .= ' ';
    }

    return $executable;
}

/** @return list<array{line: int, condition: string}> */
function proxyGeneratedShellBareSubshellIfConditions(string $source): array
{
    $conditions = [];
    $heredocLanguage = null;

    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            continue;
        }

        if ($token[0] === T_START_HEREDOC) {
            preg_match('/<<<[\'\"]?(?<language>[A-Z][A-Z0-9_]*)[\'\"]?/', $token[1], $heredoc);
            $heredocLanguage = $heredoc['language'] ?? null;

            continue;
        }

        if ($token[0] === T_END_HEREDOC) {
            $heredocLanguage = null;

            continue;
        }

        if ($heredocLanguage === 'PYTHON') {
            continue;
        }

        $literal = proxyGeneratedShellLiteralForBareSubshellIfGuard($token);
        if ($literal === null) {
            continue;
        }

        $executable = proxyGeneratedShellExecutableTextForBareSubshellIfGuard($literal);

        $matchCount = preg_match_all(
            '/(?m)^\h*(?<condition>(?:if|elif)\h+(?:!\h*)?\()(?!\()/',
            $executable,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        if ($matchCount === false) {
            throw new RuntimeException('Could not inspect generated shell conditions.');
        }

        foreach ($matches as $match) {
            $conditions[] = [
                'line' => $token[2] + substr_count(substr($literal, 0, $match[0][1]), "\n"),
                'condition' => $match['condition'][0],
            ];
        }
    }

    return $conditions;
}

it('recognizes positive and negated bare subshell conditions only inside generated strings', function (): void {
    $source = <<<'PHP'
<?php

if ($phpCondition) {
    return;
}

$script = <<<'SH'
# don't let prose quoting hide later shell code
awk '
if (NF < 2) {
    exit 1
}
' input
printf '%s\n' 'if ('
if (
  test -e first
  test -e second
); then
  true
fi
if ! (
  test -e third
  test -e fourth
); then
  true
fi
SH;
$python = <<<'PYTHON'
if (
    first_value,
    second_value,
):
    pass
PYTHON;
PHP;

    expect(array_column(proxyGeneratedShellBareSubshellIfConditions($source), 'condition'))
        ->toBe(['if (', 'if ! (']);
});

it('forbids bare subshell if conditions across every production proxy shell owner', function (): void {
    $actionsRoot = dirname(__DIR__, 2).'/app/Actions/';
    $owners = proxyGeneratedShellOwnersForBareSubshellIfGuard();
    $violations = [];

    expect($owners)->not->toBeEmpty();

    foreach ($owners as $owner) {
        $source = file_get_contents($owner);
        if ($source === false) {
            throw new RuntimeException("Could not read generated shell owner: $owner");
        }

        foreach (proxyGeneratedShellBareSubshellIfConditions($source) as $condition) {
            $violations[] = sprintf(
                'app/Actions/%s:%d emits `%s`',
                substr($owner, strlen($actionsRoot)),
                $condition['line'],
                $condition['condition'],
            );
        }
    }

    expect($violations)->toBe([]);
});
