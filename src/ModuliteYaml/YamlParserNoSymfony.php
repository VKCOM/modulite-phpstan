<?php

namespace ModulitePHPStan\ModuliteYaml;

/**
 * The purpose of this class is to avoid "symfony/yaml" dependency.
 * It successfully parses a limited subset of .yaml files,
 * which is quite enough for .modulite.yaml
 * (no links, strings are quoted, only two levels of depth)
 */
class YamlParserNoSymfony {
  static public function parseFromString(string $yaml): array {
    return self::parseYamlContents(preg_split('/\n/', $yaml), 'user-string');
  }

  static public function parseFromFile(string $file_name): array {
    return self::parseYamlContents(file($file_name), $file_name);
  }

  static private function parseYamlContents(array $lines, string $file_name): array {
    /** @var mixed[] $out */
    $out = [];

    $last_key = null;
    $last_key_nested = null;

    $assignValueAtCurrentKey = function(?string $value) use (&$last_key, &$last_key_nested, &$out) {
      if ($last_key_nested !== null) {
        $out[$last_key][$last_key_nested] = $value;
      } else if ($last_key !== null) {
        $out[$last_key] = $value;
      }
    };

    $pushValueAtCurrentKey = function(string $value) use (&$last_key, &$last_key_nested, &$out) {
      if ($last_key_nested !== null) {
        $out[$last_key][$last_key_nested][] = $value;
      } else if ($last_key !== null) {
        $out[$last_key][] = $value;
      }
    };

    foreach ($lines as $i => $line) {
      $line = rtrim($line);
      $offset = 0;
      self::skipSpaces($line, $offset);
      $n_spaces = $offset;

      if ($offset >= strlen($line)) {
        continue;
      }

      if ($line[$offset] === '#') {
        continue;
      }

      if ($line[$offset] === '-') {
        if ($last_key === null || is_string($out[$last_key])) {
          throw new YamlParserNoSymfonyException($file_name, "list in a strange place", $i + 1);
        }
        $offset++;
        $value = self::parseString($line, $offset);
        if ($value === null) {
          throw new YamlParserNoSymfonyException($file_name, "expected a string", $i + 1);
        }
        $pushValueAtCurrentKey($value);
        continue;
      }

      $nameBeforeColon = self::parseNameBeforeColon($line, $offset);
      if ($nameBeforeColon === null) {
        throw new YamlParserNoSymfonyException($file_name, "expected ':'", $i + 1);
      }

      if ($n_spaces > 0) {  // support only 2 depth levels
        $last_key_nested = $nameBeforeColon;
      } else {
        $last_key = $nameBeforeColon;
        $last_key_nested = null;
      }

      self::skipSpaces($line, $offset);
      if ($offset < strlen($line) && $line[$offset] !== '#') {
        $value = self::parseString($line, $offset);
        if ($value === null) {
          throw new YamlParserNoSymfonyException($file_name, "expected a string", $i + 1);
        }
        $assignValueAtCurrentKey($value);
      } else {
        $assignValueAtCurrentKey(null);
      }
    }

    return $out;
  }

  static private function parseString(string $line, int &$offset): ?string {
    self::skipSpaces($line, $offset);
    if ($offset >= strlen($line)) {
      return null;
    }

    return $line[$offset] === '"'
      ? self::parseQuotedString($line, $offset)
      : self::parseNonQuotedString($line, $offset);
  }

  static private function skipSpaces(string $line, int &$offset) {
    while ($offset < strlen($line) && $line[$offset] === ' ') {
      $offset++;
    }
  }

  static private function parseNameBeforeColon(string $line, int &$offset): ?string {
    $name = '';

    if ($line[$offset] === '"' || $line[$offset] === "'") {
      $name = self::parseQuotedString($line, $offset);
      if ($name === null || $offset >= strlen($line) || $line[$offset] !== ':') {
        return null;
      }
    } else {
      $pos = strpos($line, ':', $offset);
      if ($pos === false) {
        return null;
      }
      $name = substr($line, $offset, $pos - $offset);
      $offset = $pos;
    }

    $offset = $offset + 1;
    return $name;
  }

  static private function parseNonQuotedString(string $line, int &$offset): ?string {
    $value = '';

    for ($i = $offset; $i < strlen($line); ++$i) {
      switch ($line[$i]) {
        case '#':
          break 2;
        default:
          $value .= $line[$i];
      }
    }

    while ($i > $offset && $line[$i - 1] === ' ') {
      $i--;
      $value = substr($value, 0, -1);
    }

    $offset = $i;
    return $value;
  }

  static private function parseQuotedString(string $line, int &$offset): ?string {
    $quote = $line[$offset];  // " or '
    $value = '';

    for ($i = $offset + 1; $i < strlen($line); ++$i) {
      switch ($line[$i]) {
        case '\\':
          $value .= $line[++$i];
          break;
        case $quote:
          break 2;
        default:
          $value .= $line[$i];
      }
    }

    if ($i == strlen($line) || $line[$i] !== $quote) {
      return null;
    }

    $offset = $i + 1;
    return $value;
  }
}
