<?php
declare(strict_types=1);

namespace JTarleton\Sprig;

/**
 * Lexer
 * -----
 * Turns a template string into a stream of tokens. Twig templates have two
 * layers — static text, and expressions/statements embedded in `{{ }}`,
 * `{% %}`, `{# #}`. The lexer is essentially a small state machine that
 * scans for those openers and switches modes.
 *
 * Real Twig also supports things like whitespace control (`{%-`, `-%}`),
 * string interpolation, and configurable delimiters; we skip all of that.
 */

final class Token
{
    public const EOF         = 'EOF';
    public const TEXT        = 'TEXT';
    public const VAR_START   = 'VAR_START';   // {{
    public const VAR_END     = 'VAR_END';     // }}
    public const BLOCK_START = 'BLOCK_START'; // {%
    public const BLOCK_END   = 'BLOCK_END';   // %}
    public const NAME        = 'NAME';        // identifier
    public const NUMBER      = 'NUMBER';
    public const STRING      = 'STRING';
    public const OPERATOR    = 'OPERATOR';
    public const PUNCTUATION = 'PUNCTUATION';

    public function __construct(
        public readonly string $type,
        public readonly mixed $value,
        public readonly int $line,
    ) {}
}

/**
 * A cursor over a list of tokens with peek / consume helpers.
 * The Parser walks through this stream.
 */
final class TokenStream
{
    private int $cursor = 0;

    /** @param Token[] $tokens */
    public function __construct(private readonly array $tokens) {}

    public function current(): Token
    {
        return $this->tokens[$this->cursor];
    }

    public function look(int $n = 1): Token
    {
        return $this->tokens[$this->cursor + $n] ?? new Token(Token::EOF, null, 0);
    }

    public function next(): Token
    {
        return $this->tokens[$this->cursor++];
    }

    public function test(string $type, mixed $value = null): bool
    {
        $t = $this->current();
        return $t->type === $type && ($value === null || $t->value === $value);
    }

    public function expect(string $type, mixed $value = null): Token
    {
        $t = $this->current();
        if ($t->type !== $type || ($value !== null && $t->value !== $value)) {
            $expected = $value !== null ? "$type(" . var_export($value, true) . ")" : $type;
            $got      = sprintf('%s(%s)', $t->type, var_export($t->value, true));
            throw new \RuntimeException("Expected $expected, got $got on line {$t->line}");
        }
        return $this->next();
    }

    public function isEOF(): bool
    {
        return $this->current()->type === Token::EOF;
    }
}

final class Lexer
{
    /** Words that look like identifiers but act as operators. */
    private const KEYWORD_OPERATORS  = ['and', 'or', 'not', 'in'];
    private const TWO_CHAR_OPERATORS = ['==', '!=', '<=', '>=', '&&', '||'];

    private string $source = '';
    private int $cursor = 0;
    private int $line = 1;
    /** @var Token[] */
    private array $tokens = [];

    public function tokenize(string $source): TokenStream
    {
        $this->source = $source;
        $this->cursor = 0;
        $this->line   = 1;
        $this->tokens = [];

        while ($this->cursor < strlen($this->source)) {
            $this->lexData();
        }
        $this->tokens[] = new Token(Token::EOF, null, $this->line);

        return new TokenStream($this->tokens);
    }

    /** Scan static text until we find the next `{{`, `{%`, or `{#`. */
    private function lexData(): void
    {
        $remaining = substr($this->source, $this->cursor);

        if (preg_match('/(\{\{|\{%|\{#)/', $remaining, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[0][1];
            $delim  = $m[0][0];

            if ($offset > 0) {
                $text = substr($remaining, 0, $offset);
                $this->tokens[] = new Token(Token::TEXT, $text, $this->line);
                $this->line    += substr_count($text, "\n");
            }
            $this->cursor += $offset + 2;

            match ($delim) {
                '{{' => $this->lexExpression(Token::VAR_END,   '}}'),
                '{%' => $this->lexExpression(Token::BLOCK_END, '%}'),
                '{#' => $this->lexComment(),
            };
        } else {
            // No more delimiters — the rest is plain text.
            $this->tokens[] = new Token(Token::TEXT, $remaining, $this->line);
            $this->cursor   = strlen($this->source);
        }
    }

    private function lexComment(): void
    {
        $end = strpos($this->source, '#}', $this->cursor);
        if ($end === false) {
            throw new \RuntimeException("Unclosed comment starting on line {$this->line}");
        }
        $this->line  += substr_count(substr($this->source, $this->cursor, $end - $this->cursor), "\n");
        $this->cursor = $end + 2;
    }

    /**
     * Inside `{{ ... }}` or `{% ... %}`: lex names, numbers, strings,
     * operators, and punctuation until we hit the closing delimiter.
     */
    private function lexExpression(string $endType, string $endStr): void
    {
        $startType = $endType === Token::VAR_END ? Token::VAR_START : Token::BLOCK_START;
        $this->tokens[] = new Token($startType, null, $this->line);

        while ($this->cursor < strlen($this->source)) {
            // Whitespace
            if (preg_match('/\s+/A', $this->source, $m, 0, $this->cursor)) {
                $this->line   += substr_count($m[0], "\n");
                $this->cursor += strlen($m[0]);
                continue;
            }

            // Closing delimiter
            if (substr($this->source, $this->cursor, 2) === $endStr) {
                $this->cursor  += 2;
                $this->tokens[] = new Token($endType, null, $this->line);
                return;
            }

            // Number
            if (preg_match('/\d+(\.\d+)?/A', $this->source, $m, 0, $this->cursor)) {
                $value          = str_contains($m[0], '.') ? (float)$m[0] : (int)$m[0];
                $this->tokens[] = new Token(Token::NUMBER, $value, $this->line);
                $this->cursor  += strlen($m[0]);
                continue;
            }

            // String literal — single or double quoted, no escapes for simplicity.
            if (preg_match('/"([^"]*)"|\'([^\']*)\'/A', $this->source, $m, 0, $this->cursor)) {
                $value          = $m[2] ?? $m[1];
                $this->tokens[] = new Token(Token::STRING, $value, $this->line);
                $this->cursor  += strlen($m[0]);
                continue;
            }

            // Identifier (might actually be a keyword operator like `and`)
            if (preg_match('/[a-zA-Z_][a-zA-Z0-9_]*/A', $this->source, $m, 0, $this->cursor)) {
                $name = $m[0];
                $type = in_array($name, self::KEYWORD_OPERATORS, true) ? Token::OPERATOR : Token::NAME;
                $this->tokens[] = new Token($type, $name, $this->line);
                $this->cursor  += strlen($name);
                continue;
            }

            // Two-char operator
            $two = substr($this->source, $this->cursor, 2);
            if (in_array($two, self::TWO_CHAR_OPERATORS, true)) {
                $this->tokens[] = new Token(Token::OPERATOR, $two, $this->line);
                $this->cursor  += 2;
                continue;
            }

            // Single character
            $ch = $this->source[$this->cursor];
            if (str_contains('()[]{}.,|:', $ch)) {
                $this->tokens[] = new Token(Token::PUNCTUATION, $ch, $this->line);
                $this->cursor++;
                continue;
            }
            if (str_contains('+-*/=<>~', $ch)) {
                $this->tokens[] = new Token(Token::OPERATOR, $ch, $this->line);
                $this->cursor++;
                continue;
            }

            throw new \RuntimeException("Unexpected character '$ch' on line {$this->line}");
        }

        throw new \RuntimeException("Unclosed expression on line {$this->line}");
    }
}
