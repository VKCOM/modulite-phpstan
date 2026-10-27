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
    // since php-parser 5 (PHPStan 2), a doc comment is attached to the statement, not to the Assign inside it
    return Node\Stmt\Expression::class;
  }

  protected function doProcessNode(Node $node, Scope $scope, array &$errors): void {
    if (!$node instanceof Node\Stmt\Expression) {
      throw new ShouldNotHappenException("got \$node of class " . get_class($node));
    }
    if (!$node->expr instanceof Node\Expr\Assign) {
      return;
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
