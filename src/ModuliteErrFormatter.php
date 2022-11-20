<?php

namespace ModulitePHPStan;

use ModulitePHPStan\ModuliteYaml\ModuliteData;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\GlobalConstantReflection;

// this class is close to ModuliteErr in KPHP
class ModuliteErrFormatter {
  private ModuliteCheckRules $checker;
  private ?ModuliteData $inside_m;
  private ?ModuliteData $another_m;
  private Scope $usage_context;
  private string $desc;

  private function __construct(ModuliteCheckRules $checker, ?ModuliteData $inside_m, ?ModuliteData $another_m, Scope $usage_context, string $desc) {
    $this->checker = $checker;
    $this->inside_m = $inside_m;
    $this->another_m = $another_m;
    $this->usage_context = $usage_context;
    $this->desc = $desc;
  }


  static function use_class(Scope $usage_context, ClassReflection $klass, ModuliteCheckRules $checker): self {
    return new self(
      $checker,
      $checker->detector->detectModuliteOfScope($usage_context),
      $checker->detector->detectModuliteOfClass($klass),
      $usage_context,
      "use " . $klass->getName()
    );
  }

  static function call_function(Scope $usage_context, FunctionReflection $function, ModuliteCheckRules $checker): self {
    return new self(
      $checker,
      $checker->detector->detectModuliteOfScope($usage_context),
      $checker->detector->detectModuliteOfFunction($function),
      $usage_context,
      "call " . $function->getName() . "()"
    );
  }

  static function call_method(Scope $usage_context, ClassReflection $klass, string $method_name, ModuliteCheckRules $checker): self {
    return new self(
      $checker,
      $checker->detector->detectModuliteOfScope($usage_context),
      $checker->detector->detectModuliteOfClass($klass),
      $usage_context,
      "call " . $klass->getName() . "::$method_name()"
    );
  }

  static function use_constant(Scope $usage_context, ClassReflection $klass, string $const_name, ModuliteCheckRules $checker): self {
    return new self(
      $checker,
      $checker->detector->detectModuliteOfScope($usage_context),
      $checker->detector->detectModuliteOfClass($klass),
      $usage_context,
      "use " . $klass->getName()
    );
  }

  static function use_class_property(Scope $usage_context, ClassReflection $klass, string $property_name, ModuliteCheckRules $checker): self {
    return new self(
      $checker,
      $checker->detector->detectModuliteOfScope($usage_context),
      $checker->detector->detectModuliteOfClass($klass),
      $usage_context,
      "use " . $klass->getName()
    );
  }

  static function use_global_const(Scope $usage_context, GlobalConstantReflection $constant, ModuliteCheckRules $checker): self {
    return new self(
      $checker,
      $checker->detector->detectModuliteOfScope($usage_context),
      $checker->detector->detectModuliteOfGlobalConst($constant),
      $usage_context,
      "use " . $constant->getName()
    );
  }

  static function use_global_var(Scope $usage_context, string $global_var_name, ModuliteCheckRules $checker): self {
    return new self(
      $checker,
      $checker->detector->detectModuliteOfScope($usage_context),
      null,
      $usage_context,
      "use global \$" . $global_var_name
    );
  }

  function print_error_symbol_is_not_exported(array &$errors): void {
    $errors[] = "[modulite] restricted to $this->desc, it's internal in {$this->another_m->modulite_name}";
  }

  function print_error_submodulite_is_not_exported(array &$errors): void {
    assert($this->another_m->parent && !$this->checker->is_submodulite_exported($this->another_m, $this->usage_context));
    $child_internal = $this->another_m;
    while ($this->checker->is_submodulite_exported($child_internal, $this->usage_context, false)) {
      $child_internal = $child_internal->parent;
    }
    $errors[] = "[modulite] restricted to $this->desc, {$child_internal->modulite_name} is internal in {$child_internal->parent->modulite_name}";
  }

  function print_error_modulite_is_not_required(array &$errors): void {
    if ($this->inside_m->composer_json && $this->another_m->composer_json) {
      $errors[] = "[modulite] restricted to $this->desc, {$this->another_m->modulite_name} is not required by {$this->inside_m->modulite_name} in composer.json";
    } else {
      $errors[] = "[modulite] restricted to $this->desc, {$this->another_m->modulite_name} is not required by {$this->inside_m->modulite_name}";
    }
  }

  function print_error_symbol_is_not_required(array &$errors): void {
    if ($this->inside_m->is_composer_package) {
      $errors[] = "[modulite] restricted to $this->desc, it does not belong to package {$this->inside_m->modulite_name}";
    } else {
      $errors[] = "[modulite] restricted to $this->desc, it's not required by {$this->inside_m->modulite_name}";
    }
  }
}
