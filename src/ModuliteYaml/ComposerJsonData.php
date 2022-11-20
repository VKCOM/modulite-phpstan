<?php

namespace ModulitePHPStan\ModuliteYaml;

// represents composer.json structure (the parts we care about)
// they are used as implicit modulites
class ComposerJsonData {
  // absolute path to composer.json file
  public string $json_filename;

  // "name", e.g. "vk/common"
  public string $package_name;

  // "require" and "require-dev", only package names: [ "vkcom/kphp-polyfills", ... ]
  /** @var string[] */
  public array $require = [];

  static function parseFromFile(string $filename): ?self {
    $c = json_decode(file_get_contents($filename), true);
    if (!is_array($c) || !isset($c['name']) || !is_string($c['name'])) {
      return null;
    }

    $self = new self;
    $self->json_filename = $filename;
    $self->package_name = $c['name'];
    array_push($self->require, ...self::parseRequire($c, 'require'));
    array_push($self->require, ...self::parseRequire($c, 'require-dev'));

    return $self;
  }

  private static function parseRequire(array $c, string $key): array {
    // "require": { "vk/a": "version", ... }, we need only package names
    return isset($c[$key]) && is_array($c[$key]) ? array_keys($c[$key]) : [];
  }
}
