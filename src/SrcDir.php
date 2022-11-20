<?php

namespace ModulitePHPStan;

use ModulitePHPStan\ModuliteYaml\ComposerJsonData;
use ModulitePHPStan\ModuliteYaml\ModuliteData;

// this class is close to SrcDir in KPHP
class SrcDir {
  public string $full_dir_name;     // ends with /
  public ?SrcDir $parent_dir;

  public bool $has_composer_json = false;
  public bool $has_modulite_yaml = false;
  public ?ModuliteData $nested_files_modulite = null;

  public function __construct(string $dirname, bool $has_modulite_yaml = false, bool $has_composer_json = false) {
    $this->full_dir_name = rtrim($dirname, '/') . '/';
    $this->has_composer_json = $has_composer_json;
    $this->has_modulite_yaml = $has_modulite_yaml;
  }

  function __toString(): string {
    return $this->full_dir_name;
  }

  function parseComposerJson(): ?ComposerJsonData {
    $filename = $this->full_dir_name . 'composer.json';
    if (!file_exists($filename)) {
      return null;
    }
    return ComposerJsonData::parseFromFile($filename);
  }

  /**
   * @param SrcDir[] $allDirsSorted
   */
  function findParentDir(array $allDirsSorted): ?SrcDir {
    $pos = array_search($this, $allDirsSorted);
    for ($i = $pos === false ? -1 : $pos - 1; $i >= 0; --$i) {
      $i_dir = $allDirsSorted[$i];
      $this_starts_with = substr($this->full_dir_name, 0, strlen($i_dir->full_dir_name)) === $i_dir->full_dir_name;
      if ($this_starts_with) {
        return $i_dir;
      }
    }
    return null;
  }

  /**
   * @param SrcDir[] $allDirsSorted
   */
  static function findDirNearestToFile(string $filename, array $allDirsSorted): ?SrcDir {
    for ($dirname = dirname($filename); strlen($dirname) > 2; $dirname = dirname($dirname)) {
      $full_dir_name = $dirname . '/';
      for ($i = count($allDirsSorted) - 1; $i >= 0; --$i) {
        if ($full_dir_name === $allDirsSorted[$i]->full_dir_name) {
          return $allDirsSorted[$i];
        }
      }
    }
    return null;
  }
}
