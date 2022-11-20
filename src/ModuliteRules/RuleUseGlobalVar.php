<?php

declare(strict_types=1);

namespace ModulitePHPStan\ModuliteRules;

use ModulitePHPStan\DebugHelpers;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\ShouldNotHappenException;

class RuleUseGlobalVar extends ModuliteRuleBase {
  public function getNodeType(): string {
    return Node\Stmt\Global_::class;
  }

  protected function doProcessNode(Node $node, Scope $scope, array &$errors): void {
    if (!$node instanceof Node\Stmt\Global_) {
      throw new ShouldNotHappenException("got \$node of class " . get_class($node));
    }

    foreach ($node->vars as $var) {
      if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
        $global_var_name = $var->name;
//        $this->debug($global_var_name, $scope);
        $this->checker->modulite_check_when_global_keyword($scope, $global_var_name, $errors);
      }
    }
  }

  private function debug(string $global_var_name, Scope $scope) {
    $cur_place = DebugHelpers::stringifyScope($scope);
    $cur_file = DebugHelpers::stringifyLocation($scope->getFile());

    echo "use global $global_var_name from $cur_place ($cur_file)", "\n";
  }
}
