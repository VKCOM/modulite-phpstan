<?php

declare(strict_types=1);

namespace ModulitePHPStan\ModuliteRules;

use ModulitePHPStan\DebugHelpers;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\ShouldNotHappenException;

class RuleCallGlobalFunc extends ModuliteRuleBase {
  public function getNodeType(): string {
    return Node\Expr\FuncCall::class;
  }

  protected function doProcessNode(Node $node, Scope $scope, array &$errors): void {
    if (!$node instanceof Node\Expr\FuncCall) {
      throw new ShouldNotHappenException("got \$node of class " . get_class($node));
    }
    if (!$node->name instanceof Node\Name) {    // $some_fn()
      return;
    }

    $f = $this->reflector->getFunction($node->name, $scope);
//    $this->debug($f, $scope);
    $this->checker->modulite_check_when_call_global_function($scope, $f, $errors);
  }

  private function debug(\PHPStan\Reflection\FunctionReflection $f, Scope $scope) {
    $f_name = $f->getName();
    $cur_place = DebugHelpers::stringifyScope($scope);
    $ref_loc = DebugHelpers::stringifyLocation($f->getFileName());

    echo "call $f_name() ($ref_loc) from $cur_place", "\n";
  }
}
