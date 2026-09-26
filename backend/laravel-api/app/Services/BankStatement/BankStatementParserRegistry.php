<?php

namespace App\Services\BankStatement;

/**
 * All known bank formats. Adding a bank later = one new class + one line here.
 */
class BankStatementParserRegistry
{
    /** @var BankStatementParser[] */
    private array $parsers;

    public function __construct()
    {
        $this->parsers = [
            new BcaStatementParser(),
            new MandiriStatementParser(),
        ];
    }

    public function get(string $code): BankStatementParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->code() === $code) {
                return $parser;
            }
        }

        throw new \InvalidArgumentException("Unknown bank statement format: {$code}");
    }

    /** Auto-detect from file content; null if no parser recognizes it (caller falls back to manual selection). */
    public function detect(string $rawContent): ?string
    {
        foreach ($this->parsers as $parser) {
            if ($parser->detect($rawContent)) {
                return $parser->code();
            }
        }

        return null;
    }

    /** @return string[] */
    public function codes(): array
    {
        return array_map(fn (BankStatementParser $p) => $p->code(), $this->parsers);
    }
}
