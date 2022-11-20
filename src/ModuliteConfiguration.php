<?php

namespace ModulitePHPStan;

class ModuliteConfiguration {
  private string $cwd;
  private array $parameters;

  function __construct(string $currentWorkingDirectory, array $parameters) {
    $this->cwd = $currentWorkingDirectory;
    $this->parameters = $parameters;
  }

  private function calcRealPath(string $relativePath): ?string {
    $abs_path = realpath($relativePath[0] === '/' ? $relativePath : "$this->cwd/$relativePath");
    return $abs_path ?: null;
  }

  private function errPathNotFound(string $configItemName): string {
    return "Path not found: '{$this->parameters[$configItemName]}' (modulite > $configItemName)\nCurrent working dir: $this->cwd";
  }

  /** @return (string|null)[]|string [projectRoot, srcRoot, additionalPackagesRoot] | error */
  function detectProjectRoots() {
    $projectRoot = $this->parameters['projectRoot'] ?? '';
    if (!$projectRoot) {
      return "Could not detect project root (where composer.json and vendor/ folder exist).\nPlease, provide 'parameters > modulite > projectRoot' (string) in your PHPStan config.";
    }
    $projectRoot = $this->calcRealPath($projectRoot);
    if (!$projectRoot) {
      return $this->errPathNotFound('projectRoot');
    }

    $srcRoot = $this->parameters['srcRoot'] ?? '';
    if (!$srcRoot) {
      return "Could not detect src root to locate .modulite.yaml files.\nPlease, provide 'parameters > modulite > srcRoot' (string) in your PHPStan config.";
    }
    $srcRoot = $this->calcRealPath($srcRoot);
    if (!$srcRoot) {
      return $this->errPathNotFound('srcRoot');
    }

    $additionalPackagesRoot = $this->parameters['additionalPackagesRoot'] ?? null;
    if ($additionalPackagesRoot) {
      $additionalPackagesRoot = $this->calcRealPath($additionalPackagesRoot);
      if (!$additionalPackagesRoot) {
        return $this->errPathNotFound('additionalPackagesRoot');
      }
    }

    return [$projectRoot, $srcRoot, $additionalPackagesRoot];
  }
}
