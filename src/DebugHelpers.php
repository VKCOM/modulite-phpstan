<?php

namespace ModulitePHPStan;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Type;

class DebugHelpers {
  static function isFileBuiltin(?string $filename): bool {
    return empty($filename) || str_ends_with($filename, '.stub');
  }

  static function stringifyLocation(?string $filename): string {
    if (self::isFileBuiltin($filename)) {
      return 'builtin';
    }
    return basename($filename);
  }

  static function stringifyScope(Scope $scope): string {
    $f = $scope->getFunction();
    if (!$f) {
      return "global scope";
    }

    if ($f instanceof MethodReflection) {
      return $f->getDeclaringClass()->getName() . '::' . $f->getName() . '()';
    }
    if ($f instanceof FunctionReflection) {
      return $f->getName() . '()';
    }
    return '(none)';
  }

  static function stringifyType(?Type $type): string {
    if ($type === null) {
      return '';
    }
    return $type->describe(\PHPStan\Type\VerbosityLevel::getRecommendedLevelByType($type));
  }
}
