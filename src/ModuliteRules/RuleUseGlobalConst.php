<?php

declare(strict_types=1);

namespace ModulitePHPStan\ModuliteRules;

use ModulitePHPStan\DebugHelpers;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\ShouldNotHappenException;

class RuleUseGlobalConst extends ModuliteRuleBase {
  private const BUILTIN_GLOBAL_CONSTANTS = ['null', 'true', 'false', 'NULL'];

  public function getNodeType(): string {
    return Node\Expr\ConstFetch::class;
  }

  protected function doProcessNode(Node $node, Scope $scope, array &$errors): void {
    if (!$node instanceof Node\Expr\ConstFetch) {
      throw new ShouldNotHappenException("got \$node of class " . get_class($node));
    }

    if (!$this->reflector->hasConstant($node->name, $scope)) {
      return;
    }

    $const = $this->reflector->getConstant($node->name, $scope);
    if (in_array($const->getName(), self::BUILTIN_GLOBAL_CONSTANTS, true)) {
      return;
    }

//    $this->debug($const, $scope);
    $this->checker->modulite_check_when_use_global_const($scope, $const, $errors);
  }

  private function debug(\PHPStan\Reflection\GlobalConstantReflection $const, Scope $scope) {
    $c_name = $const->getName();
    $cur_place = DebugHelpers::stringifyScope($scope);
    $ref_loc = DebugHelpers::stringifyLocation($const->getFileName());

    echo "use $c_name ($ref_loc) from $cur_place", "\n";
  }
}
