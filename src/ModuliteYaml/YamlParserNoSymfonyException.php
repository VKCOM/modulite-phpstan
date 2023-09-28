<?php

namespace ModulitePHPStan\ModuliteYaml;

class YamlParserNoSymfonyException extends \RuntimeException {
  public function __construct(string $file_name, string $message, int $line_number) {
    parent::__construct($message);
    $this->file = $file_name;
    $this->line = $line_number;
  }
}
