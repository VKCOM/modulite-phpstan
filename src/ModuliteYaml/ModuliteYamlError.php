<?php

namespace ModulitePHPStan\ModuliteYaml;

class ModuliteYamlError {
  private string $message;
  private string $yaml_filename;
  private int $line;

  public function __construct(string $yaml_filename, string $message, int $line = 0) {
    $rel_filename = substr($yaml_filename, strlen(dirname($yaml_filename, 2)) + 1);
    $this->message = str_ends_with($yaml_filename, '.yaml') ? "Failed loading $rel_filename: $message" : $message;
    $this->yaml_filename = $yaml_filename;
    $this->line = $line;
  }

  public function getMessage(): string {
    return $this->message;
  }

  public function getYamlFilename(): string {
    return $this->yaml_filename;
  }

  public function getLine(): int {
    return $this->line;
  }
}
