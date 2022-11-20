<?php

declare(strict_types=1);

namespace ModulitePHPStan\ModuliteRules;

use ModulitePHPStan\DebugHelpers;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\ShouldNotHappenException;

class RuleInstanceof extends ModuliteRuleBase {
  public function getNodeType(): string {
    return Node\Expr\Instanceof_::class;
  }

  protected function doProcessNode(Node $node, Scope $scope, array &$errors): void {
    if (!$node instanceof Node\Expr\Instanceof_) {
      throw new ShouldNotHappenException("got \$node of class " . get_class($node));
    }
    if (!$node->class instanceof Node\Name) {   // instanceof $class_name
      return;
    }

    $class_resolved = $scope->resolveName($node->class);
    if (!$this->reflector->hasClass($class_resolved)) {
      return;
    }

    $klass = $this->reflector->getClass($class_resolved, $scope);
//    $this->debug($klass, $scope);
    $this->checker->modulite_check_when_use_class($scope, $klass, $errors);
  }

  private function debug(\PHPStan\Reflection\ClassReflection $klass, Scope $scope) {
    $c_name = $klass->getName();
    $cur_place = DebugHelpers::stringifyScope($scope);
    $ref_loc = DebugHelpers::stringifyLocation($klass->getFileName());

    echo "instanceof $c_name ($ref_loc) from $cur_place", "\n";
  }
}
