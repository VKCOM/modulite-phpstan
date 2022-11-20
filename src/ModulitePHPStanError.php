<?php

namespace ModulitePHPStan;

use PHPStan\Rules\FileRuleError;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\LineRuleError;

class ModulitePHPStanError implements IdentifierRuleError, LineRuleError, FileRuleError {
  const IDENTIFIER = 'modulite.rule.error';

  private string $message;
  private string $file;
  private int $line;

  public function __construct(string $message, string $file, int $line) {
    $this->message = $message;
    $this->file = $file;
    $this->line = $line;
  }

  public function getFile(): string {
    return $this->file;
  }

  public function getIdentifier(): string {
    return self::IDENTIFIER;
  }

  public function getLine(): int {
    return $this->line;
  }

  public function getMessage(): string {
    return $this->message;
  }
}
