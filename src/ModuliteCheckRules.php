<?php

namespace ModulitePHPStan;

use ModulitePHPStan\ModuliteYaml\ModuliteData;
use ModulitePHPStan\ModuliteYaml\ModuliteSymbol;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\GlobalConstantReflection;

// IMPORTANT! keep this file and logic very close to modulite-check-rules.cpp in KPHP
class ModuliteCheckRules {
  public ModuliteFromReflectionDetector $detector;

  public function __construct(ModuliteFromReflectionDetector $detector) {
    $this->detector = $detector;
  }

  static private function any_of(array $arr, callable $cb): bool {
    foreach ($arr as $elem) {
      if ($cb($elem)) {
        return true;
      }
    }
    return false;
  }

  private function does_allow_internal_rule_satisfy_usage_context(Scope $usage_context, ModuliteSymbol $rule): bool {
    $inside_f = $usage_context->getFunction();
    $inside_klass = $usage_context->getClassReflection();
    $nn = $inside_f ? $inside_f->getName() : "";

    return ($rule->kind === ModuliteSymbol::kind_function && $inside_f && $rule->function->getName() === $inside_f->getName()) ||
      ($rule->kind === ModuliteSymbol::kind_method && $inside_klass && $rule->klass->getName() === $inside_klass->getName() && $rule->member_name === $inside_f->getName()) ||
      ($rule->kind === ModuliteSymbol::kind_klass && $inside_klass && $rule->klass->getName() === $inside_klass->getName()) ||
      ($rule->kind === ModuliteSymbol::kind_modulite && $rule->modulite === $this->detector->detectModuliteOfScope($usage_context));
  }

  static private function should_this_usage_context_be_ignored(Scope $usage_context) {
    return false;
  }

  // class A is exported from a modulite when
  // - "A" is declared in 'export'
  // - or "A" is declared in 'allow internal' for current usage context
  private function is_class_exported_from(ClassReflection $klass, ModuliteData $owner, Scope $usage_context): bool {
    foreach ($owner->exports as $e) {
      if ($e->kind === ModuliteSymbol::kind_klass && $e->klass->getName() === $klass->getName()) {
        return true;
      }
    }

    /** @var ModuliteSymbol[] $values */
    foreach ($owner->allow_internal as list($key, $values)) {
      if ($this->does_allow_internal_rule_satisfy_usage_context($usage_context, $key)) {
        foreach ($values as $e) {
          if ($e->kind === ModuliteSymbol::kind_klass && $e->klass->getName() === $klass->getName()) {
            return true;
          }
        }
      }
    }

    if ($owner->is_composer_package) {   // when it's an implicit modulite created from composer.json, all is exported
      return empty($owner->exports);     // (unless .modulite.yaml exists near composer.json and manually provides "export")
    }

    return false;
  }

  // function globalF() is exported from a modulite when
  // - "\\globalF()" is declared in 'export' (already checked that it's declared inside a modulite)
  private function is_function_exported_from(FunctionReflection $function, ModuliteData $owner, Scope $usage_context): bool {
    foreach ($owner->exports as $e) {
      if ($e->kind === ModuliteSymbol::kind_function && $e->function->getName() === $function->getName()) {
        return true;
      }
    }

    /** @var ModuliteSymbol[] $values */
    foreach ($owner->allow_internal as list($key, $values)) {
      if ($this->does_allow_internal_rule_satisfy_usage_context($usage_context, $key)) {
        foreach ($values as $e) {
          if ($e->kind === ModuliteSymbol::kind_function && $e->function->getName() === $function->getName()) {
            return true;
          }
        }
      }
    }

    if ($owner->is_composer_package) {   // when it's an implicit modulite created from composer.json, all is exported
      return empty($owner->exports);     // (unless .modulite.yaml exists near composer.json and manually provides "export")
    }

    return false;
  }

  // function A::f is exported from a modulite when
  // - "A::f" is declared in 'export'
  // - or "A" is declared in 'export' AND "A::f" is not declared in 'force-internal'
  // - or either "A" or "A::f" is declared in 'allow internal' for current usage context
  private function is_method_exported_from(string $method_name, ClassReflection $requested_class, ModuliteData $owner, Scope $usage_context): bool {
    foreach ($owner->exports as $e) {
      if ($e->kind === ModuliteSymbol::kind_method && $e->klass->getName() === $requested_class->getName() && $e->member_name === $method_name) {
        return true;
      }
      if ($e->kind === ModuliteSymbol::kind_klass && $e->klass->getName() === $requested_class->getName()) {
        foreach ($owner->force_internal as $fi) {
          if ($fi->kind === ModuliteSymbol::kind_method && $fi->member_name === $method_name) {
            break 2;
          }
        }
        return true;
      }
    }

    /** @var ModuliteSymbol[] $values */
    foreach ($owner->allow_internal as list($key, $values)) {
      if ($this->does_allow_internal_rule_satisfy_usage_context($usage_context, $key)) {
        foreach ($values as $e) {
          if ($e->kind === ModuliteSymbol::kind_method && $e->klass->getName() === $requested_class->getName() && $e->member_name === $method_name) {
            return true;
          }
          if ($e->kind === ModuliteSymbol::kind_klass && $e->klass->getName() === $requested_class->getName()) {
            return true;
          }
        }
      }
    }

    if ($owner->is_composer_package) {   // when it's an implicit modulite created from composer.json, all is exported
      return empty($owner->exports);     // (unless .modulite.yaml exists near composer.json and manually provides "export")
    }

    return false;
  }

  // constant GLOBAL_DEFINE is exported from a modulite when
  // - "\\GLOBAL_DEFINE" is declared in 'export' (already checked that it's declared inside a modulite)
  private function is_global_const_exported_from(string $const_name, ModuliteData $owner, Scope $usage_context): bool {
    foreach ($owner->exports as $e) {
      if ($e->kind === ModuliteSymbol::kind_global_const && $e->global_name === $const_name) {
        return true;
      }
    }

    /** @var ModuliteSymbol[] $values */
    foreach ($owner->allow_internal as list($key, $values)) {
      if ($this->does_allow_internal_rule_satisfy_usage_context($usage_context, $key)) {
        foreach ($values as $e) {
          if ($e->kind === ModuliteSymbol::kind_global_const && $e->global_name === $const_name) {
            return true;
          }
        }
      }
    }

    if ($owner->is_composer_package) {   // when it's an implicit modulite created from composer.json, all is exported
      return empty($owner->exports);     // (unless .modulite.yaml exists near composer.json and manually provides "export")
    }

    return false;
  }

  // constant A::C is exported from a modulite when
  // - "A::C" is declared in 'export'
  // - or "A" is declared in 'export' AND "A::C" is not declared in 'force-internal'
  // - or either "A" or "A::C" is declared in 'allow internal' for current usage context
  private function is_constant_exported_from(string $const_name, ClassReflection $requested_class, ModuliteData $owner, Scope $usage_context): bool {
    foreach ($owner->exports as $e) {
      if ($e->kind === ModuliteSymbol::kind_constant && $e->klass->getName() === $requested_class->getName() && $e->member_name === $const_name) {
        return true;
      }
      if ($e->kind === ModuliteSymbol::kind_klass && $e->klass->getName() === $requested_class->getName()) {
        foreach ($owner->force_internal as $fi) {
          if ($fi->kind === ModuliteSymbol::kind_constant && $fi->member_name === $const_name) {
            break 2;
          }
        }
        return true;
      }
    }

    /** @var ModuliteSymbol[] $values */
    foreach ($owner->allow_internal as list($key, $values)) {
      if ($this->does_allow_internal_rule_satisfy_usage_context($usage_context, $key)) {
        foreach ($values as $e) {
          if ($e->kind === ModuliteSymbol::kind_constant && $e->klass->getName() === $requested_class->getName() && $e->member_name === $const_name) {
            return true;
          }
          if ($e->kind === ModuliteSymbol::kind_klass && $e->klass->getName() === $requested_class->getName()) {
            return true;
          }
        }
      }
    }

    if ($owner->is_composer_package) {   // when it's an implicit modulite created from composer.json, all is exported
      return empty($owner->exports);     // (unless .modulite.yaml exists near composer.json and manually provides "export")
    }

    return false;
  }

  // static field A::$f is exported from a modulite when
  // - "A::$f" is declared in 'export'
  // - or "A" is declared in 'export' AND "A::$f" is not declared in 'force-internal'
  // - or either "A" or "A::$f" is declared in 'allow internal' for current usage context
  private function is_static_field_exported_from(string $property_name, ClassReflection $requested_class, ModuliteData $owner, Scope $usage_context): bool {
    foreach ($owner->exports as $e) {
      if ($e->kind === ModuliteSymbol::kind_property && $e->member_name === $property_name) {
        return true;
      }
      if ($e->kind === ModuliteSymbol::kind_klass && $e->klass->getName() === $requested_class->getName()) {
        foreach ($owner->force_internal as $fi) {
          if ($fi->kind === ModuliteSymbol::kind_property && $fi->klass->getName() === $requested_class->getName() && $fi->member_name === $property_name) {
            break 2;
          }
        }
        return true;
      }
    }

    /** @var ModuliteSymbol[] $values */
    foreach ($owner->allow_internal as list($key, $values)) {
      if ($this->does_allow_internal_rule_satisfy_usage_context($usage_context, $key)) {
        foreach ($values as $e) {
          if ($e->kind === ModuliteSymbol::kind_property && $e->klass->getName() === $requested_class->getName() && $e->member_name === $property_name) {
            return true;
          }
          if ($e->kind === ModuliteSymbol::kind_klass && $e->klass->getName() === $requested_class->getName()) {
            return true;
          }
        }
      }
    }

    if ($owner->is_composer_package) {   // when it's an implicit modulite created from composer.json, all is exported
      return empty($owner->exports);     // (unless .modulite.yaml exists near composer.json and manually provides "export")
    }

    return false;
  }

  // submodulite @msg/core is exported from @msg when
  // - "@msg/core" is declared in 'export'
  // - or "@msg/core" is declared in 'allow-internal' for current usage context
  // - or usage context is @msg/channels, it can access @msg/core, because @msg is their lca
  // if check_all_depth, checks are done for any chains, e.g. @parent/c1/c2/c3 — c3 from c2, c2 from c1, c1 from parent
  // else, check is not only for child from child->parent (c3 from c2 if given above)
  public function is_submodulite_exported(ModuliteData $child, Scope $usage_context, bool $check_all_depth = true): bool {
    $parent = $child->parent;

    if ($child->exported_from_parent) {
      return !$check_all_depth || !$parent->parent || $this->is_submodulite_exported($parent, $usage_context);
    }

    /** @var ModuliteSymbol[] $values */
    foreach ($parent->allow_internal as list($key, $values)) {
      if ($this->does_allow_internal_rule_satisfy_usage_context($usage_context, $key)) {
        foreach ($values as $e) {
          if ($e->kind === ModuliteSymbol::kind_modulite && $e->modulite === $child) {
            return !$check_all_depth || !$parent->parent || $this->is_submodulite_exported($parent, $usage_context);
          }
        }
      }
    }

    if ($this->detector->detectModuliteOfScope($usage_context) && $parent === $this->detector->detectModuliteOfScope($usage_context)->find_lca_with($parent)) {
      return true;
    }

    if ($parent->is_composer_package) {  // when parent is an implicit modulite created from composer.json, all is exported
      return empty($parent->exports);    // (unless .modulite.yaml exists near composer.json and manually provides "export")
    }

    return false;
  }

  private function does_require_another_modulite(ModuliteData $inside_m, ModuliteData $another_m): bool {
    // examples: we are at inside_m = @feed, accessing another_m = @msg/channels
    // fast path: if @feed requires @msg/channels
    foreach ($inside_m->require as $req) {
      if ($req->kind === ModuliteSymbol::kind_modulite && $req->modulite === $another_m) {
        return true;
      }
    }

    // slow path: if @feed requires @msg, then @msg/channels is auto-required unless internal in @msg (for any depth)
    // same for composer packages: if @feed requires #vk/common, #vk/common/@strings is auto-required unless not exported
    foreach ($inside_m->require as $req) {
      if ($req->kind === ModuliteSymbol::kind_modulite && in_array($another_m, $req->modulite->submodulites_exported_at_any_depth, true)) {
        return true;
      }
    }

    // contents of composer packages can also use modulite; when it's embedded into a monolith, it's like a global scope
    // so, in a way project root can access any modulite (there is no place to provide "require"),
    // a root of composer package can access any modulite within this package
    if ($inside_m->is_composer_package && $another_m->composer_json === $inside_m->composer_json) {
      return true;
    }

    return false;
  }

  function modulite_check_when_use_class(Scope $usage_context, ClassReflection $klass, array &$errors): void {
    $inside_m = $this->detector->detectModuliteOfScope($usage_context);
    $another_m = $this->detector->detectModuliteOfClass($klass);
    if ($inside_m === $another_m || self::should_this_usage_context_be_ignored($usage_context)) {
      return;
    }

    if ($another_m) {
      if (!$this->is_class_exported_from($klass, $another_m, $usage_context)) {
        ModuliteErrFormatter::use_class($usage_context, $klass, $this)->print_error_symbol_is_not_exported($errors);
        return;
      }

      if ($another_m->parent && !$this->is_submodulite_exported($another_m, $usage_context)) {
        ModuliteErrFormatter::use_class($usage_context, $klass, $this)->print_error_submodulite_is_not_exported($errors);
        return;
      }
    }

    if ($inside_m) {
      $should_require_m_instead = $another_m && (!$another_m->is_composer_package || $another_m->composer_json !== $inside_m->composer_json);
      if ($should_require_m_instead) {
        if (!$this->does_require_another_modulite($inside_m, $another_m)) {
          ModuliteErrFormatter::use_class($usage_context, $klass, $this)->print_error_modulite_is_not_required($errors);
        }

      } else {
        $in_require = self::any_of($inside_m->require,
          fn(ModuliteSymbol $s) => $s->kind === ModuliteSymbol::kind_klass && $s->klass->getName() === $klass->getName()
        );
        if (!$in_require && !$this->detector->isClassBuiltin($klass)) {
          ModuliteErrFormatter::use_class($usage_context, $klass, $this)->print_error_symbol_is_not_required($errors);
        }
      }
    }
  }

  function modulite_check_when_call_global_function(Scope $usage_context, FunctionReflection $called_f, array &$errors): void {
    $inside_m = $this->detector->detectModuliteOfScope($usage_context);
    $another_m = $this->detector->detectModuliteOfFunction($called_f);
    if ($inside_m === $another_m || self::should_this_usage_context_be_ignored($usage_context)) {
      return;
    }

    if ($another_m) {
      if (!$this->is_function_exported_from($called_f, $another_m, $usage_context)) {
        ModuliteErrFormatter::call_function($usage_context, $called_f, $this)->print_error_symbol_is_not_exported($errors);
        return;
      }

      if ($another_m->parent && !$this->is_submodulite_exported($another_m, $usage_context)) {
        ModuliteErrFormatter::call_function($usage_context, $called_f, $this)->print_error_submodulite_is_not_exported($errors);
        return;
      }
    }

    if ($inside_m) {
      $should_require_m_instead = $another_m && (!$another_m->is_composer_package || $another_m->composer_json !== $inside_m->composer_json);
      if ($should_require_m_instead) {
        if (!$this->does_require_another_modulite($inside_m, $another_m)) {
          ModuliteErrFormatter::call_function($usage_context, $called_f, $this)->print_error_modulite_is_not_required($errors);
        }

      } else {
        $in_require = self::any_of($inside_m->require,
          fn(ModuliteSymbol $s) => $s->kind === ModuliteSymbol::kind_function && $s->function->getName() === $called_f->getName()
        );
        if (!$in_require && !$this->detector->isFunctionBuiltin($called_f)) {
          ModuliteErrFormatter::call_function($usage_context, $called_f, $this)->print_error_symbol_is_not_required($errors);
        }
      }
    }
  }

  function modulite_check_when_call_method(Scope $usage_context, string $method_name, ClassReflection $requested_class, array &$errors): void {
    $inside_m = $this->detector->detectModuliteOfScope($usage_context);
    $another_m = $this->detector->detectModuliteOfClass($requested_class);
    if ($inside_m === $another_m || self::should_this_usage_context_be_ignored($usage_context)) {
      return;
    }

    if ($another_m) {
      if (!$this->is_method_exported_from($method_name, $requested_class, $another_m, $usage_context)) {
        ModuliteErrFormatter::call_method($usage_context, $requested_class, $method_name, $this)->print_error_symbol_is_not_exported($errors);
        return;
      }

      if ($another_m->parent && !$this->is_submodulite_exported($another_m, $usage_context)) {
        ModuliteErrFormatter::call_method($usage_context, $requested_class, $method_name, $this)->print_error_submodulite_is_not_exported($errors);
        return;
      }
    }

    if ($inside_m) {
      $should_require_m_instead = $another_m && (!$another_m->is_composer_package || $another_m->composer_json !== $inside_m->composer_json);
      if ($should_require_m_instead) {
        if (!$this->does_require_another_modulite($inside_m, $another_m)) {
          ModuliteErrFormatter::call_method($usage_context, $requested_class, $method_name, $this)->print_error_modulite_is_not_required($errors);
        }

      } else {
        $in_require = self::any_of($inside_m->require,
          fn(ModuliteSymbol $s) => $s->kind === ModuliteSymbol::kind_method && $s->klass->getName() === $requested_class->getName() && $s->member_name === $method_name
        );
        if (!$in_require && !$this->detector->isClassBuiltin($requested_class)) {
          ModuliteErrFormatter::call_method($usage_context, $requested_class, $method_name, $this)->print_error_symbol_is_not_required($errors);
        }
      }
    }
  }

  function modulite_check_when_use_global_const(Scope $usage_context, GlobalConstantReflection $used_c, array &$errors): void {
    $inside_m = $this->detector->detectModuliteOfScope($usage_context);
    $another_m = $this->detector->detectModuliteOfGlobalConst($used_c);
    if ($inside_m === $another_m || self::should_this_usage_context_be_ignored($usage_context)) {
      return;
    }

    if ($another_m) {
      if (!$this->is_global_const_exported_from($used_c->getName(), $another_m, $usage_context)) {
        ModuliteErrFormatter::use_global_const($usage_context, $used_c, $this)->print_error_symbol_is_not_exported($errors);
        return;
      }

      if ($another_m->parent && !$this->is_submodulite_exported($another_m, $usage_context)) {
        ModuliteErrFormatter::use_global_const($usage_context, $used_c, $this)->print_error_submodulite_is_not_exported($errors);
        return;
      }
    }

    if ($inside_m) {
      $should_require_m_instead = $another_m && (!$another_m->is_composer_package || $another_m->composer_json !== $inside_m->composer_json);
      if ($should_require_m_instead) {
        if (!$this->does_require_another_modulite($inside_m, $another_m)) {
          ModuliteErrFormatter::use_global_const($usage_context, $used_c, $this)->print_error_modulite_is_not_required($errors);
        }

      } else {
        $in_require = self::any_of($inside_m->require,
          fn(ModuliteSymbol $s) => ($s->kind === ModuliteSymbol::kind_global_const && $s->global_name === $used_c->getName())
        );
        if (!$in_require && !$this->detector->isGlobalConstBuiltin($used_c)) {
          ModuliteErrFormatter::use_global_const($usage_context, $used_c, $this)->print_error_symbol_is_not_required($errors);
        }
      }
    }
  }

  function modulite_check_when_use_constant(Scope $usage_context, string $const_name, ClassReflection $requested_class, array &$errors): void {
    $inside_m = $this->detector->detectModuliteOfScope($usage_context);
    $another_m = $this->detector->detectModuliteOfClass($requested_class);
    if ($inside_m === $another_m || self::should_this_usage_context_be_ignored($usage_context)) {
      return;
    }

    if ($another_m) {
      if (!$this->is_constant_exported_from($const_name, $requested_class, $another_m, $usage_context)) {
        ModuliteErrFormatter::use_constant($usage_context, $requested_class, $const_name, $this)->print_error_symbol_is_not_exported($errors);
        return;
      }

      if ($another_m->parent && !$this->is_submodulite_exported($another_m, $usage_context)) {
        ModuliteErrFormatter::use_constant($usage_context, $requested_class, $const_name, $this)->print_error_submodulite_is_not_exported($errors);
        return;
      }
    }

    if ($inside_m) {
      $should_require_m_instead = $another_m && (!$another_m->is_composer_package || $another_m->composer_json !== $inside_m->composer_json);
      if ($should_require_m_instead) {
        if (!$this->does_require_another_modulite($inside_m, $another_m)) {
          ModuliteErrFormatter::use_constant($usage_context, $requested_class, $const_name, $this)->print_error_modulite_is_not_required($errors);
        }

      } else {
        $in_require = self::any_of($inside_m->require,
          fn(ModuliteSymbol $s) => ($s->kind === ModuliteSymbol::kind_klass && $s->klass->getName() === $requested_class->getName()) ||
            ($s->kind === ModuliteSymbol::kind_constant && $s->klass->getName() === $requested_class->getName() && $s->member_name === $const_name)
        );
        if (!$in_require && !$this->detector->isClassBuiltin($requested_class)) {
          ModuliteErrFormatter::use_constant($usage_context, $requested_class, $const_name, $this)->print_error_symbol_is_not_required($errors);
        }
      }
    }
  }

  function modulite_check_when_use_static_field(Scope $usage_context, string $property_name, ClassReflection $requested_class, array &$errors): void {
    $inside_m = $this->detector->detectModuliteOfScope($usage_context);
    $another_m = $this->detector->detectModuliteOfClass($requested_class);
    if ($inside_m === $another_m || self::should_this_usage_context_be_ignored($usage_context)) {
      return;
    }

    if ($another_m) {
      if (!$this->is_static_field_exported_from($property_name, $requested_class, $another_m, $usage_context)) {
        ModuliteErrFormatter::use_class_property($usage_context, $requested_class, $property_name, $this)->print_error_symbol_is_not_exported($errors);
        return;
      }

      if ($another_m->parent && !$this->is_submodulite_exported($another_m, $usage_context)) {
        ModuliteErrFormatter::use_class_property($usage_context, $requested_class, $property_name, $this)->print_error_submodulite_is_not_exported($errors);
        return;
      }
    }

    if ($inside_m) {
      $should_require_m_instead = $another_m && (!$another_m->is_composer_package || $another_m->composer_json !== $inside_m->composer_json);
      if ($should_require_m_instead) {
        if (!$this->does_require_another_modulite($inside_m, $another_m)) {
          ModuliteErrFormatter::use_class_property($usage_context, $requested_class, $property_name, $this)->print_error_modulite_is_not_required($errors);
        }

      } else {
        $in_require = self::any_of($inside_m->require,
          fn(ModuliteSymbol $s) => ($s->kind === ModuliteSymbol::kind_klass && $s->klass->getName() === $requested_class->getName()) ||
            ($s->kind === ModuliteSymbol::kind_property && $s->klass->getName() === $requested_class->getName() && $s->member_name === $property_name)
        );
        if (!$in_require && !$this->detector->isClassBuiltin($requested_class)) {
          ModuliteErrFormatter::use_class_property($usage_context, $requested_class, $property_name, $this)->print_error_symbol_is_not_required($errors);
        }
      }
    }
  }

  function modulite_check_when_global_keyword(Scope $usage_context, string $global_var_name, array &$errors): void {
    $inside_m = $this->detector->detectModuliteOfScope($usage_context);
    if (self::should_this_usage_context_be_ignored($usage_context)) {
      return;
    }

    if ($inside_m) {
      $in_require = self::any_of($inside_m->require,
        fn(ModuliteSymbol $s) => $s->kind === ModuliteSymbol::kind_global_var && $s->global_name === $global_var_name
      );
      if (!$in_require && !$inside_m->is_composer_package) {  // composer root, like project root, has no place to declare requires
        ModuliteErrFormatter::use_global_var($usage_context, $global_var_name, $this)->print_error_symbol_is_not_required($errors);
      }
    }
  }
}
