<?php

declare(strict_types=1);

namespace ModulitePHPStan\ModuliteRules;

use ModulitePHPStan\DebugHelpers;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\ShouldNotHappenException;

class RuleDeclareProperty extends ModuliteRuleBase {
  public function getNodeType(): string {
    return Node\Stmt\Property::class;
  }

  protected function doProcessNode(Node $node, Scope $scope, array &$errors): void {
    if (!$node instanceof Node\Stmt\Property) {
      throw new ShouldNotHappenException("got \$node of class " . get_class($node));
    }

    $klass = $scope->getClassReflection();
    foreach ($node->props as $prop) {
      $property = $klass->getProperty($prop->name->name, $scope);
//      $this->debug($property, $prop->name->name, $scope);
      $this->checkUseClassesFromPhpdoc($property->getReadableType(), $scope, $errors);
    }
  }

  private function debug(PropertyReflection $prop, string $prop_name, Scope $scope) {
    $msg_static = $prop->isStatic() ? 'static ' : '';
    $class_name = $prop->getDeclaringClass()->getName();
    $cur_file = DebugHelpers::stringifyLocation($scope->getFile());
    $msg_prop_type = DebugHelpers::stringifyType($prop->getReadableType());

    echo "declare $msg_static$class_name::\$$prop_name : $msg_prop_type ($cur_file)", "\n";
  }
}
