<?php

declare(strict_types=1);

namespace ModulitePHPStan\ModuliteRules;

use ModulitePHPStan\DebugHelpers;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\Tag\VarTag;
use PHPStan\ShouldNotHappenException;

class RuleAssignVar extends ModuliteRuleBase {
  public function getNodeType(): string {
    return Node\Expr\Assign::class;
  }

  protected function doProcessNode(Node $node, Scope $scope, array &$errors): void {
    if (!$node instanceof Node\Expr\Assign) {
      throw new ShouldNotHappenException("got \$node of class " . get_class($node));
    }

    $docComment = $node->getDocComment();
    if (!$docComment) {
      return;
    }
//    $this->debug($docComment->getText(), $scope);

    $klass = $scope->getClassReflection();
    /** @var VarTag[] $var_tags */
    $var_tags = $this->fileTypeMapper->getResolvedPhpDoc(
      $scope->getFile(),
      $klass ? $klass->getName() : null,
      null,   // don't support traits
      $scope->getFunctionName(),
      $docComment->getText()
    )->getVarTags();

    foreach ($var_tags as $var_name => $var_tag) {
      self::checkUseClassesFromPhpdoc($var_tag->getType(), $scope, $errors);
    }
  }

  private function debug(string $docText, Scope $scope) {
    $cur_place = DebugHelpers::stringifyScope($scope);

    echo "phpdoc $docText at $cur_place", "\n";
  }
}
