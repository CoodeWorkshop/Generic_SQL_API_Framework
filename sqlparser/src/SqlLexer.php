<?php

require_once __DIR__ . '/SqlParserException.php';

class SqlLexer
{
    public function tokenize(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);

        for ($index = 0; $index < $length;) {
            $character = $sql[$index];

            if ($this->isWhitespace($character)) {
                $index++;
                continue;
            }
            if ($character === '-' && ($sql[$index + 1] ?? '') === '-') {
                $index += 2;
                while ($index < $length && $sql[$index] !== "\n") {
                    $index++;
                }
                continue;
            }
            if ($character === '/' && ($sql[$index + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $index + 2);
                if ($end === false) {
                    throw new SqlParserException('Unterminated block comment.', $index);
                }
                $index = $end + 2;
                continue;
            }

            // SQL Server Unicode strings use N'...'. Keep the prefix out of the value.
            if (($character === 'N' || $character === 'n') && ($sql[$index + 1] ?? '') === "'") {
                $index++;
                $tokens[] = $this->scanString($sql, $index, $length);
                continue;
            }
            if ($character === "'") {
                $tokens[] = $this->scanString($sql, $index, $length);
                continue;
            }
            if ($character === '[') {
                $tokens[] = $this->scanBracketedIdentifier($sql, $index, $length);
                continue;
            }
            if ($character === '"') {
                $tokens[] = $this->scanQuotedIdentifier($sql, $index, $length);
                continue;
            }
            if ($this->isDigit($character)) {
                $start = $index;
                while ($index < $length && $this->isDigit($sql[$index])) {
                    $index++;
                }
                if (($sql[$index] ?? '') === '.') {
                    $index++;
                    while ($index < $length && $this->isDigit($sql[$index])) {
                        $index++;
                    }
                }
                $raw = substr($sql, $start, $index - $start);
                $tokens[] = [
                    'type' => 'number',
                    'value' => str_contains($raw, '.') ? (float) $raw : (int) $raw,
                    'position' => $start,
                ];
                continue;
            }
            if ($this->isAlpha($character) || $character === '_' || $character === '#') {
                $start = $index++;
                while ($index < $length
                    && ($this->isAlphaNumeric($sql[$index]) || in_array($sql[$index], ['_', '$', '#'], true))) {
                    $index++;
                }
                $tokens[] = [
                    'type' => 'word',
                    'value' => substr($sql, $start, $index - $start),
                    'position' => $start,
                ];
                continue;
            }

            $twoCharacters = substr($sql, $index, 2);
            if (in_array($twoCharacters, ['>=', '<=', '<>', '!='], true)) {
                $tokens[] = ['type' => 'symbol', 'value' => $twoCharacters, 'position' => $index];
                $index += 2;
                continue;
            }
            if (str_contains('(),.*+-/%;=><', $character)) {
                $tokens[] = ['type' => 'symbol', 'value' => $character, 'position' => $index++];
                continue;
            }

            throw new SqlParserException("Unexpected character '{$character}'.", $index);
        }

        $tokens[] = ['type' => 'eof', 'value' => '', 'position' => $length];
        return $tokens;
    }

    private function isWhitespace(string $character): bool
    {
        return str_contains(" \t\n\r\0\x0B", $character);
    }

    private function isDigit(string $character): bool
    {
        return $character >= '0' && $character <= '9';
    }

    private function isAlpha(string $character): bool
    {
        return ($character >= 'A' && $character <= 'Z') || ($character >= 'a' && $character <= 'z');
    }

    private function isAlphaNumeric(string $character): bool
    {
        return $this->isAlpha($character) || $this->isDigit($character);
    }

    private function scanString(string $sql, int &$index, int $length): array
    {
        $start = $index++;
        $value = '';
        while ($index < $length) {
            if ($sql[$index] === "'" && ($sql[$index + 1] ?? '') === "'") {
                $value .= "'";
                $index += 2;
                continue;
            }
            if ($sql[$index] === "'") {
                $index++;
                return ['type' => 'string', 'value' => $value, 'position' => $start];
            }
            $value .= $sql[$index++];
        }
        throw new SqlParserException('Unterminated string literal.', $start);
    }

    private function scanBracketedIdentifier(string $sql, int &$index, int $length): array
    {
        $start = $index++;
        $value = '';
        while ($index < $length) {
            if ($sql[$index] === ']' && ($sql[$index + 1] ?? '') === ']') {
                $value .= ']';
                $index += 2;
                continue;
            }
            if ($sql[$index] === ']') {
                $index++;
                return ['type' => 'identifier', 'value' => $value, 'position' => $start];
            }
            $value .= $sql[$index++];
        }
        throw new SqlParserException('Unterminated bracketed identifier.', $start);
    }

    private function scanQuotedIdentifier(string $sql, int &$index, int $length): array
    {
        $start = $index++;
        $value = '';
        while ($index < $length) {
            if ($sql[$index] === '"' && ($sql[$index + 1] ?? '') === '"') {
                $value .= '"';
                $index += 2;
                continue;
            }
            if ($sql[$index] === '"') {
                $index++;
                return ['type' => 'identifier', 'value' => $value, 'position' => $start];
            }
            $value .= $sql[$index++];
        }
        throw new SqlParserException('Unterminated quoted identifier.', $start);
    }
}
