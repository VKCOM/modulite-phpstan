<?php

declare(strict_types=1);

namespace ModulitePHPStan\ModuliteRules;

use ModulitePHPStan\DebugHelpers;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\ShouldNotHappenException;

class RuleDeclareClass extends ModuliteRuleBase {
  public function getNodeType(): string {
    return Node\Stmt\ClassLike::class;
  }

  protected function doProcessNode(Node $node, Scope $scope, array &$errors): void {
    if (!$node instanceof Node\Stmt\ClassLike) {
      throw new ShouldNotHappenException("got \$node of class " . get_class($node));
    }

    $class_name = $node->namespacedName
      ? $scope->resolveName($node->namespacedName)
      : ($node->name ? $node->name->name : null);
    if (!$class_name) {
      return;
    }

    $klass = $this->reflector->getClass($class_name);
//        $this->debug($klass, $scope);

    if ($parent_class = $klass->getParentClass()) {
      $this->checker->modulite_check_when_use_class($scope, $parent_class, $errors);
    }
    foreach ($klass->getImmediateInterfaces() as $parent_interface) {
      $this->checker->modulite_check_when_use_class($scope, $parent_interface, $errors);
    }
  }

  private function debug(\PHPStan\Reflection\ClassReflection $klass, Scope $scope) {
    if ($klass->isTrait()) {
      $msg_kind = 'trait';
    } else if ($klass->isEnum()) {
      $msg_kind = 'enum';
    } else if ($klass->isInterface()) {
      $msg_kind = 'interface';
    } else {
      $msg_kind = 'class';
    }

    $msg_extends = '';
    if ($klass->getParentClass()) {
      $msg_extends .= ' extends ' . $klass->getParentClass()->getName();
    }
    if ($klass->getImmediateInterfaces()) {
      $msg_extends .= ' implements ' . implode(', ', array_map(fn($p) => $p->getName(), $klass->getImmediateInterfaces()));
    }
    $c_name = $klass->getName();
    $cur_file = DebugHelpers::stringifyLocation($scope->getFile());

    echo "declare $msg_kind $c_name$msg_extends ($cur_file)", "\n";
  }
}
