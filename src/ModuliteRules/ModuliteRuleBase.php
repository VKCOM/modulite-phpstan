<?php

namespace ModulitePHPStan\ModuliteRules;

use ModulitePHPStan\ModuliteCheckRules;
use ModulitePHPStan\ModulitePHPStanError;
use ModulitePHPStan\ModuliteService;
use ModulitePHPStan\ModuliteYaml\ModuliteYamlError;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\Type;
use PHPStan\Type\TypeWithClassName;

abstract class ModuliteRuleBase implements Rule {
  private ModuliteService $service;
  protected ModuliteCheckRules $checker;
  protected ReflectionProvider $reflector;
  protected FileTypeMapper $fileTypeMapper;

  function __construct(ModuliteService $service) {
    $this->service = $service;
    if (!$service->hasAnyYamlError()) {
      $this->checker = $service->checker;
      $this->reflector = $service->reflector;
      $this->fileTypeMapper = $service->fileTypeMapper;
    }
  }

  /** @return RuleError[] */
  public function processNode(Node $node, Scope $scope): array {
    if ($this->service->hasAnyYamlError()) {
      return $this->convertYamlErrorsToPHPStanErrors($this->service->dumpAllYamlErrors());
    }

    /** @var string[] $errors */
    $errors = [];
    $this->doProcessNode($node, $scope, $errors);
    if (empty($errors)) {
      return [];
    }

    return array_map(fn($msg) => new ModulitePHPStanError(
      $msg,
      $scope->getFile(),
      $scope->getFileDescription(),
      $node->getLine()
    ), $errors);
  }

  /**
   * @param ModuliteYamlError[] $yaml_errors
   * @return RuleError[]
   */
  private function convertYamlErrorsToPHPStanErrors(array $yaml_errors): array {
    return array_map(function(ModuliteYamlError $err) {
      return new ModulitePHPStanError($err->getMessage(), $err->getYamlFilename(), $err->getYamlFilename(), $err->getLine());
    }, $yaml_errors);
  }

  abstract protected function doProcessNode(Node $node, Scope $scope, array &$errors): void;

  protected function checkUseClassesFromPhpdoc(?Type $type, Scope $scope, array &$errors) {
    if ($type instanceof TypeWithClassName) {
      if ($this->reflector->hasClass($type->getClassName())) {
        $klass = $this->reflector->getClass($type->getClassName());
        $this->checker->modulite_check_when_use_class($scope, $klass, $errors);
      }
    } else if ($type !== null) {
      $type->traverse(function(?Type $sub) use ($scope, &$errors) {
        $this->checkUseClassesFromPhpdoc($sub, $scope, $errors);
        return $sub;
      });
    }
  }
}
