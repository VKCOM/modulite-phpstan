<?php

namespace ModulitePHPStan;

use ModulitePHPStan\ModuliteYaml\ModuliteData;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\GlobalConstantReflection;
use PHPStan\Reflection\MethodReflection;

class ModuliteFromReflectionDetector {
  /** @var SrcDir[] Dirs with modulites inside, sorted from short to long (so that a parent appears first) */
  private array $allDirsWithModulites;
  /** @var string[] [dir_outside_vendor/ => dir_in_vendor/] */
  private array $composerDirsOutsideMapToVendor = [];

  /** @var (ModuliteData|null)[] [filename => ?ModuliteData) */
  private array $cached_filename_to_modulite = [];

  function __construct(array $allDirsSorted, array $composerDirsOutsideMapToVendor) {
    $this->allDirsWithModulites = $allDirsSorted;
    $this->composerDirsOutsideMapToVendor = $composerDirsOutsideMapToVendor;
  }

  private function detectModuliteOfFile(?string $php_file): ?ModuliteData {
    if (empty($php_file)) {
      return null;
    }

    if (array_key_exists($php_file, $this->cached_filename_to_modulite)) {
      return $this->cached_filename_to_modulite[$php_file];
    }

    $php_file_mapped = str_replace('composer/../', '', $php_file);
    foreach ($this->composerDirsOutsideMapToVendor as $outside => $in_vendor) {
      if (str_starts_with($php_file, $outside)) {
        $php_file_mapped = $in_vendor . substr($php_file, strlen($outside));
        break;
      }
    }

    $dir = SrcDir::findDirNearestToFile($php_file_mapped, $this->allDirsWithModulites);
    $modulite = $dir ? $dir->nested_files_modulite : null;

    $this->cached_filename_to_modulite[$php_file] = $modulite;
    return $modulite;
  }

  function detectModuliteOfScope(Scope $scope): ?ModuliteData {
    return $this->detectModuliteOfFile($scope->getFile());
  }

  function detectModuliteOfClass(ClassReflection $klass): ?ModuliteData {
    return $this->detectModuliteOfFile($klass->getFileName());
  }

  function detectModuliteOfFunction(FunctionReflection $function): ?ModuliteData {
    return $this->detectModuliteOfFile($function->getFileName());
  }

  function detectModuliteOfMethod(MethodReflection $method): ?ModuliteData {
    return $this->detectModuliteOfFile($method->getDeclaringClass()->getFileName());
  }

  function detectModuliteOfGlobalConst(GlobalConstantReflection $const): ?ModuliteData {
    return $this->detectModuliteOfFile($const->getFileName());
  }

  function isFileBuiltin(?string $filename): bool {
    return empty($filename) || str_ends_with($filename, '.stub');
  }

  function isClassBuiltin(ClassReflection $klass): bool {
    return $this->isFileBuiltin($klass->getFileName());
  }

  function isFunctionBuiltin(FunctionReflection $function): bool {
    return $function->isBuiltin();
  }

  function isGlobalConstBuiltin(GlobalConstantReflection $const): bool {
    return $this->isFileBuiltin($const->getFileName());
  }
}
