<?php

declare(strict_types=1);

namespace ModulitePHPStan\ModuliteRules;

use ModulitePHPStan\DebugHelpers;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\Type;

class RuleDeclareGlobalFunc extends ModuliteRuleBase {
  public function getNodeType(): string {
    return Node\Stmt\Function_::class;
  }

  protected function doProcessNode(Node $node, Scope $scope, array &$errors): void {
    if (!$node instanceof Node\Stmt\Function_) {
      throw new ShouldNotHappenException("got \$node of class " . get_class($node));
    }

    $f = $this->reflector->getFunction($node->namespacedName, $scope);
    $acc = \PHPStan\Reflection\ParametersAcceptorSelector::selectSingle($f->getVariants());
    /** @var ParameterReflection[] $parameters */
    $parameters = $acc->getParameters();
    foreach ($parameters as $param) {
      $this->checkUseClassesFromPhpdoc($param->getType(), $scope, $errors);
    }
    $this->checkUseClassesFromPhpdoc($acc->getReturnType(), $scope, $errors);
  }

  /**
   * @param ParameterReflection[] $params
   */
  private function debug(\PHPStan\Reflection\FunctionReflection $f, Type $return_type, array $params, Scope $scope) {
    $f_name = $f->getName();
    $cur_file = DebugHelpers::stringifyLocation($scope->getFile());
    $msg_return_type = DebugHelpers::stringifyType($return_type);

    $msg_args = implode(', ', array_map(function(ParameterReflection $p) {
      $type = DebugHelpers::stringifyType($p->getType());
      $opt_sign = $p->isOptional() ? '?' : '';
      $var_sign = $p->isVariadic() ? '...' : '';
      return "$type $opt_sign$var_sign\${$p->getName()}";
    }, $params));

    echo "declare $f_name($msg_args):$msg_return_type ($cur_file)", "\n";
  }
}
