<?php

declare(strict_types=1);

namespace ModulitePHPStan\ModuliteRules;

use ModulitePHPStan\DebugHelpers;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\ShouldNotHappenException;

class RuleCallStaticMethod extends ModuliteRuleBase {
  public function getNodeType(): string {
    return Node\Expr\StaticCall::class;
  }

  protected function doProcessNode(Node $node, Scope $scope, array &$errors): void {
    if (!$node instanceof Node\Expr\StaticCall) {
      throw new ShouldNotHappenException("got \$node of class " . get_class($node));
    }
    if (!$node->class instanceof Node\Name) {   // $class_name::CONST
      return;
    }
    if (!$node->name instanceof Node\Identifier) {  // User::$some_method()
      return;
    }

    $class_resolved = $scope->resolveName($node->class);    // deal with 'self', etc.
    if (!$this->reflector->hasClass($class_resolved)) {
      return;
    }

    $klass = $this->reflector->getClass($class_resolved);
    $method_name = $node->name->toString();
    if (!$klass->hasMethod($method_name)) {
      return;
    }

    $f = $klass->getMethod($method_name, $scope);
//    $this->debug($klass, $method_name, $scope);
    $this->checker->modulite_check_when_call_method($scope, $method_name, $klass, $errors);
  }

  private function debug(\PHPStan\Reflection\ClassReflection $klass, string $method_name, Scope $scope) {
    $c_name = $klass->getName();
    $cur_place = DebugHelpers::stringifyScope($scope);
    $ref_loc = DebugHelpers::stringifyLocation($klass->getFileName());

    echo "call $c_name::$method_name() ($ref_loc) from $cur_place", "\n";
  }
}
