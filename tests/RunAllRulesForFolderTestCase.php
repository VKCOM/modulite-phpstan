<?php

namespace ModuliteTests;

use ModulitePHPStan\ModulitePHPStanError;
use PHPStan\Analyser\Analyser;
use PHPStan\Analyser\Error;
use PHPStan\Testing\PHPStanTestCase;

abstract class RunAllRulesForFolderTestCase extends PHPStanTestCase {
  private const TESTRUN_NEON_CONTENTS = <<<TXT
parameters:
    paths:
        - .
    modulite:
        projectRoot: {folder}
        srcRoot: {folder}
        additionalPackagesRoot: {folder}/packages

includes:
    - ../../../extension.neon
TXT;

  abstract static function getProjectFolderName(): string;

  public static function getAdditionalConfigFiles(): array {
    return [static::getProjectFolder() . '/testrun.neon'];
  }

  public static function getProjectFolder(): string {
    return __DIR__ . '/php/' . static::getProjectFolderName();
  }

  public static function getIndexPhpFileName(): string {
    return __DIR__ . '/php/' . static::getProjectFolderName() . '/' . static::getProjectFolderName() . '.php';
  }

  protected function setUp(): void {
    $folder = static::getProjectFolder();
    $testrun_neon_filename = "$folder/testrun.neon";
    if (!file_exists($testrun_neon_filename)) {
      $neon = self::TESTRUN_NEON_CONTENTS;
      if (!file_exists("$folder/packages")) {
        $neon = str_replace("additionalPackagesRoot", "#additionalPackagesRoot", $neon);
      }
      file_put_contents($testrun_neon_filename, str_replace('{folder}', $folder, $neon));
    }
  }

  public function testThisFolder() {
    $files = static::globAllPhpFiles(static::getProjectFolder());
    /** @var Analyser $analyser */
    $analyser = self::getContainer()->getByType(Analyser::class);
    /** @var Error[] $errors */
    $errors = $analyser->analyse($files)->getErrors();

    $this->checkErrors($this->filterOnlyModuliteErrors($errors), $this->getExpectedErrorPatterns());
  }

  /**
   * @param Error[] $all_phpstan_errors
   * @return Error[]
   */
  protected function filterOnlyModuliteErrors(array $all_phpstan_errors): array {
    return array_values(array_filter($all_phpstan_errors,
      fn($e) => $e->getIdentifier() === ModulitePHPStanError::IDENTIFIER));
  }

  /**
   * @return string[]
   */
  protected function getExpectedErrorPatterns(): array {
    $patterns = [];

    $php_file = static::getIndexPhpFileName();
    foreach (file($php_file) as $line) {
      if (substr($line, 0, 5) === '<?php') {
        break;
      }
      if ($line[0] === '/' && $line[strlen($line) - 2] === '/') { // -1 is \n
        $patterns[] = $this->normalizeErrorPatternFromPhpFileTop(substr($line, 1, strlen($line) - 3));
      }
    }

    return array_filter($patterns);
  }

  protected function normalizeErrorPatternFromPhpFileTop(string $pattern): string {
    if (substr($pattern, 0, 3) === 'in ') {
      return '';
    }
    $pattern = preg_replace('/\\\\(.)/', '$1', $pattern);
    return $pattern;
  }

  /**
   * @param Error[]  $errors
   * @param string[] $expected_patterns
   */
  protected function checkErrors(array $errors, array $expected_patterns): void {
    $failed_msg = [];

    foreach ($expected_patterns as $i => $pattern) {
      foreach ($errors as $error) {
        if ($this->doesPatternMatchError($pattern, $error)) {
          continue 2;
        }
      }
      $idx = $i + 1;
      $failed_msg[] = "pattern #$idx \"$pattern\" didn't match any errors";
    }

    if (empty($expected_patterns) && !empty($errors)) {
      $failed_msg[] = "expected @ok, got errors";
    }

    if (!empty($failed_msg)) {
      $failed_msg[] = "--- All errors, for debug:";
      foreach ($errors as $i => $error) {
        $idx = $i + 1;
        $failed_msg[] = "error #$idx \"{$error->getMessage()}\", file \"{$this->shortenFileName($error)}\"";
      }
    }

    $this->assertEmpty($failed_msg, implode("\n\n", $failed_msg) . "\n\n");
  }

  private function shortenFileName(Error $error): string {
    return substr($error->getFile(), strlen(dirname($error->getFile(), 2)) + 1);
  }

  protected function doesPatternMatchError(string $pattern, Error $error): bool {
    if (strpos($error->getMessage(), $pattern) !== false) {
      return true;
    }
    if ($this->shortenFileName($error) === $pattern) {
      return true;
    }
    if ($error->getFile() && $error->getLine()) {
      $line_of_file = file($error->getFile())[$error->getLine() - 1];
      return strpos($line_of_file, $pattern) !== false;
    }
    return false;
  }

  static function globAllPhpFiles(string $folder): array {
    $result = [];

    /** @var \RecursiveIteratorIterator|\DirectoryIterator $it */
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder));
    $it->rewind();
    while ($it->valid()) {
      $filename = $it->key();
      if (!$it->isDot()
        && substr($filename, -4) === '.php'
        && strpos($filename, "/vendor/") === false) {
        $result[] = $filename;
      }
      $it->next();
    }
    return $result;
  }

}
