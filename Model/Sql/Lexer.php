<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Model\Sql;

use Magento\Framework\Exception\LocalizedException;

/**
 * Layer 1 of the query guard: turns a raw statement into tokens and the normalised SQL rebuilt
 * from them, refusing the byte-level shapes no later layer could reason about.
 *
 * @phpstan-type SqlToken array{type: string, value: string, quoted: bool}
 */
class Lexer
{
    public const TYPE_WORD = 'word';
    public const TYPE_STRING = 'string';
    public const TYPE_NUMBER = 'number';
    public const TYPE_PUNCT = 'punct';

    // ------------------------------------------------------------------ layer 1: lexing

    /**
     * Tokenises the statement while stripping comments and collapsing whitespace.
     *
     * @param string $sql
     * @return array{sql: string, tokens: list<SqlToken>}
     * @throws LocalizedException
     */
    public function tokenize(string $sql): array
    {
        $this->assertNoControlCharacters($sql);

        $length = strlen($sql);
        $output = '';
        $tokens = [];
        $pendingSpace = false;
        $position = 0;

        while ($position < $length) {
            $char = $sql[$position];

            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                $pendingSpace = true;
                $position++;
                continue;
            }

            if ($char === '#') {
                $position = $this->skipToLineEnd($sql, $position);
                $pendingSpace = true;
                continue;
            }

            if ($char === '-' && ($sql[$position + 1] ?? '') === '-') {
                $following = $sql[$position + 2] ?? "\n";
                if (!in_array($following, [' ', "\t", "\n", "\r"], true)) {
                    throw new LocalizedException(
                        __(
                            'A `--` comment must be followed by whitespace. `%1` is ambiguous '
                            . '(MySQL reads it as arithmetic), so the query is refused.',
                            '--' . $following
                        )
                    );
                }
                $position = $this->skipToLineEnd($sql, $position);
                $pendingSpace = true;
                continue;
            }

            if ($char === '/' && ($sql[$position + 1] ?? '') === '*') {
                $marker = $sql[$position + 2] ?? '';
                // MariaDB executes `/*M!…*/` exactly as MySQL executes `/*!…*/`.
                $mariaDbForm = ($marker === 'M' || $marker === 'm') && ($sql[$position + 3] ?? '') === '!';
                if ($marker === '!' || $marker === '+' || $mariaDbForm) {
                    throw new LocalizedException(
                        __(
                            'The query contains a MySQL executable comment (`/*%1`). '
                            . 'Executable comments hide syntax from validation and are never accepted.',
                            $mariaDbForm ? $marker . '!' : $marker
                        )
                    );
                }
                $end = strpos($sql, '*/', $position + 2);
                if ($end === false) {
                    throw new LocalizedException(__('The query contains an unterminated `/* */` comment.'));
                }
                $position = $end + 2;
                $pendingSpace = true;
                continue;
            }

            if ($pendingSpace && $output !== '') {
                $output .= ' ';
            }
            $pendingSpace = false;

            if ($char === '"') {
                throw new LocalizedException(
                    __('Double-quoted values are not accepted. Use single quotes for string literals.')
                );
            }

            if ($char === '{' || $char === '}') {
                throw new LocalizedException(
                    __(
                        'The query contains `%1`. `{{table_name}}` placeholders must be resolved to real '
                        . 'table names before the query is validated.',
                        $char
                    )
                );
            }

            if ($char === "'") {
                $literal = $this->readStringLiteral($sql, $position);
                $output .= $literal['raw'];
                $tokens[] = ['type' => self::TYPE_STRING, 'value' => $literal['raw'], 'quoted' => false];
                $position = $literal['next'];
                continue;
            }

            if ($char === '`') {
                $identifier = $this->readQuotedIdentifier($sql, $position);
                $output .= $identifier['raw'];
                $tokens[] = ['type' => self::TYPE_WORD, 'value' => $identifier['value'], 'quoted' => true];
                $position = $identifier['next'];
                continue;
            }

            if ($this->isIdentifierChar($char)) {
                $word = '';
                while ($position < $length && $this->isIdentifierChar($sql[$position])) {
                    $word .= $sql[$position];
                    $position++;
                }
                $output .= $word;
                $tokens[] = [
                    'type' => ctype_digit($word) ? self::TYPE_NUMBER : self::TYPE_WORD,
                    'value' => $word,
                    'quoted' => false,
                ];
                continue;
            }

            $output .= $char;
            $tokens[] = ['type' => self::TYPE_PUNCT, 'value' => $char, 'quoted' => false];
            $position++;
        }

        return ['sql' => $output, 'tokens' => $tokens];
    }

    /**
     * @param string $sql
     * @return void
     * @throws LocalizedException
     */
    private function assertNoControlCharacters(string $sql): void
    {
        // `!== 0` rather than `=== 1`: a PCRE failure returns false and must reject, not pass.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $sql) !== 0) {
            throw new LocalizedException(
                __('The query contains a control character. Remove it and try again.')
            );
        }
    }

    /**
     * @param string $sql
     * @param int $position
     * @return int
     */
    private function skipToLineEnd(string $sql, int $position): int
    {
        $newline = strcspn($sql, "\r\n", $position);

        return $position + $newline;
    }

    /**
     * Reads a `'…'` literal. Backslashes are refused outright: whether `\` escapes the next byte
     * depends on the server's `NO_BACKSLASH_ESCAPES` sql_mode (and connection charset), so honouring
     * it could desync our literal boundaries from the server's. `''` doubling works in every mode.
     *
     * @param string $sql
     * @param int $position Index of the opening quote.
     * @return array{raw: string, next: int}
     * @throws LocalizedException
     */
    private function readStringLiteral(string $sql, int $position): array
    {
        $length = strlen($sql);
        $raw = "'";
        $position++;

        while ($position < $length) {
            $char = $sql[$position];

            if ($char === '\\') {
                throw new LocalizedException(
                    __(
                        'Backslashes are not accepted inside string literals, because whether `\\` escapes '
                        . 'the next character depends on the server\'s sql_mode. Use `\'\'` to embed a '
                        . 'single quote.'
                    )
                );
            }

            if ($char === "'") {
                if (($sql[$position + 1] ?? '') === "'") {
                    $raw .= "''";
                    $position += 2;
                    continue;
                }

                return ['raw' => $raw . "'", 'next' => $position + 1];
            }

            $raw .= $char;
            $position++;
        }

        throw new LocalizedException(__('The query contains an unterminated string literal.'));
    }

    /**
     * Reads a `` `…` `` identifier. `raw` is the literal source slice, never rebuilt from `value`:
     * `value` is undoubled, so re-wrapping it would turn an embedded `` `` `` into a live delimiter.
     *
     * @param string $sql
     * @param int $position Index of the opening backtick.
     * @return array{raw: string, value: string, next: int}
     * @throws LocalizedException
     */
    private function readQuotedIdentifier(string $sql, int $position): array
    {
        $length = strlen($sql);
        $start = $position;
        $value = '';
        $position++;

        while ($position < $length) {
            $char = $sql[$position];

            if ($char === '`') {
                if (($sql[$position + 1] ?? '') === '`') {
                    $value .= '`';
                    $position += 2;
                    continue;
                }

                return [
                    'raw' => substr($sql, $start, $position + 1 - $start),
                    'value' => $value,
                    'next' => $position + 1,
                ];
            }

            $value .= $char;
            $position++;
        }

        throw new LocalizedException(__('The query contains an unterminated `` ` `` quoted identifier.'));
    }

    /**
     * @param string $char
     * @return bool
     */
    private function isIdentifierChar(string $char): bool
    {
        return ctype_alnum($char) || $char === '_' || $char === '$' || ord($char) >= 0x80;
    }
}
