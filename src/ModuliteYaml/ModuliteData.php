<?php

namespace ModulitePHPStan\ModuliteYaml;

use ModulitePHPStan\ModuliteService;

// represents structure of .modulite.yaml
// IMPORTANT! keep this class and logic very close to ModuliteData in KPHP
class ModuliteData {
  // absolute path to .modulite.yaml file
  public string $yaml_filename;

  // "name" from yaml, starts with @, e.g. "@feed", "@messages", "@messages/channel"
  public string $modulite_name = '';
  // "namespace" from yaml, normalized: starts with symbol, ends with \, e.g. "VK\Messages\"; empty if no namespace
  public string $modulite_namespace = '';
  // for "@msg/channels", parent is "@msg"
  public ?ModuliteData $parent = null;

  // if it's a sub-modulite of a composer package (or a modulite created from composer.json itself)
  public ?ComposerJsonData $composer_json = null;
  // if it's a modulite created from composer.json (for instance, "export" is typically empty, which means all exported)
  public bool $is_composer_package = false;

  // "export" from yaml lists all exported symbols (classes, functions, submodulites, etc.)
  /** @var ModuliteSymbol[] */
  public array $exports = [];
  // denormalization: if its parent lists @this in "export" (so, it's visible always, without 'allow-internal' lookups)
  public bool $exported_from_parent = false;
  // submodulites from "export" (including exported sub-submodulites, etc.) are stored separately for optimization
  /** @var ModuliteData[] */
  public array $submodulites_exported_at_any_depth = [];

  // "force-internal" from yaml lists methods/constants making them internal despite their classes are exported
  /** @var ModuliteSymbol[] */
  public array $force_internal = [];

  // "require" from yaml lists external symbols it depends on (other modulites, composer packages, globals, etc.)
  /** @var ModuliteSymbol[] */
  public array $require = [];

  // "allow-internal-access" from yaml lists additional "export" rules for specific usage contexts
  /** @var (tuple(ModuliteSymbol, ModuliteSymbol[]))[] */
  public array $allow_internal = [];

  /** @var ModuliteYamlError[] */
  private array $errors = [];

  function __toString() {
    return $this->modulite_name;
  }

  function fire_yaml_error(string $message, int $line = 0) {
    $this->errors[] = new ModuliteYamlError($this->yaml_filename, $message, $line);
  }

  // converts "@another_m" and similar to real pointers in-place,
  // called when all modulites and composer packages are parsed and registered
  public function resolve_names_to_pointers(ModuliteService $service): void {
    foreach ($this->exports as $symbol) {
      $this->resolve_symbol_from_yaml($symbol, $service, 'export');
    }
    foreach ($this->force_internal as $symbol) {
      $this->resolve_symbol_from_yaml($symbol, $service, 'force-internal');
    }
    foreach ($this->require as $symbol) {
      $this->resolve_symbol_from_yaml($symbol, $service, 'require');
    }
    foreach ($this->allow_internal as $p_allow_rule) {
      $this->resolve_symbol_from_yaml($p_allow_rule[0], $service, 'allow-internal');
      foreach ($p_allow_rule[1] as $symbol) {
        $this->resolve_symbol_from_yaml($symbol, $service, 'allow-internal');
      }
    }

    if ($this->parent) {
      foreach ($this->parent->exports as $e) {
        if ($e->kind === ModuliteSymbol::kind_modulite && $e->modulite === $this) {
          $this->exported_from_parent = true;
          break;
        }
      }
    }

    // if a composer package is written using modulites, then all its child modulites are exported and available from the monolith
    // (unless .modulite.yaml near composer.json explicitly declares "export")
    if ($this->parent && $this->parent->is_composer_package && empty($this->parent->exports)) {
      $this->exported_from_parent = true;
    }

    // append this (inside_m) to submodulites_exported_at_any_depth all the tree up
    for ($child = $this; $child->parent; $child = $child->parent) {
      if (!$child->exported_from_parent) {
        break;
      }
      $child->parent->submodulites_exported_at_any_depth[] = $this;
    }
  }

  // resolve any config item ("SomeClass", "SomeClass::f()", "@msg", etc.) from a string to a symbol
  // this is done in-place, modifying s.kind and s.{prop}
  // on error resolving, it's also printed here, pointing to a concrete line in yaml file
  private function resolve_symbol_from_yaml(ModuliteSymbol $s, ModuliteService $service, string $section): void {
    assert($s->kind === ModuliteSymbol::kind_ref_stringname);
    $v = $s->ref_stringname;

    // @msg, @msg/channels
    if ($v[0] === '@') {
      $name = $v;
      if ($this->composer_json) {
        $name = ModuliteYamlParser::prepend_composer_package_to_name($this->composer_json, $name);
      }
      if ($m_ref = $service->getModulite($name)) {
        $s->kind = ModuliteSymbol::kind_modulite;
        $s->modulite = $m_ref;
      } else {
        $this->fire_yaml_error("modulite $v not found (in '$section')");
      }
      return;
    }

    // #composer-package
    if ($v[0] === '#') {
      if ($m_ref = $service->getModulite($v)) {
        $s->kind = ModuliteSymbol::kind_modulite;
        $s->modulite = $m_ref;
      } else {
        // in case a ref is not found, don't fire an error,
        // because composer.json often contain "php" and other strange non-installed deps in "require"
      }
      return;
    }

    $posCol = strpos($v, "::");
    $abs_name = $v[0] === '\\';

    // if no :: then it doesn't belong to a class
    if ($posCol === false) {
      // $global_var
      if ($v[0] === '$') {
        $s->kind = ModuliteSymbol::kind_global_var;
        $s->global_name = substr($v, 1);
        return;
      }
      // relative_func() or \\global_func()
      if ($v[strlen($v) - 1] === ')') {
        $f_fqn = $abs_name ? substr($v, 1, strlen($v) - 3) : $this->modulite_namespace . substr($v, 0, strlen($v) - 2);
        if ($function = $service->reflector->hasFunction(new \PhpParser\Node\Name($f_fqn), null)) {
          $s->kind = ModuliteSymbol::kind_function;
          $s->function = $service->reflector->getFunction(new \PhpParser\Node\Name($f_fqn), null);
          $s->global_name = $f_fqn;
        } else {
          $this->fire_yaml_error("can't find function $f_fqn() (in '$section')");
        }
        return;
      }
      // RelativeClass or \\GlobalClass or RELATIVE_CONST or \\GLOBAL_CONST
      $fqn = $abs_name ? substr($v, 1) : $this->modulite_namespace . $v;
      if ($service->reflector->hasClass($fqn)) {
        $s->kind = ModuliteSymbol::kind_klass;
        $s->klass = $service->reflector->getClass($fqn);
      } else if ($service->reflector->hasConstant(new \PhpParser\Node\Name($fqn), null)) {
        $s->kind = ModuliteSymbol::kind_global_const;
        $s->constant = $service->reflector->getConstant(new \PhpParser\Node\Name($fqn), null);
        $s->global_name = $fqn;
      } else {
        $this->fire_yaml_error("can't find class/constant $fqn (in '$section')");
      }

    } else {
      // if :: exists, then it's RelativeClass::{something} or \\GlobalClass::{something}
      $c_fqn = $abs_name ? substr($v, 1, $posCol - 1) : $this->modulite_namespace . substr($v, 0, $posCol);
      if (!$service->reflector->hasClass($c_fqn)) {
        $this->fire_yaml_error("can't find class $c_fqn (in '$section')");
        return;
      }
      $klass = $service->reflector->getClass($c_fqn);

      // C::$static_field
      if ($v[$posCol + 2] === '$') {
        $local_name = substr($v, $posCol + 3);
        if ($klass->hasProperty($local_name)) {
          $s->kind = ModuliteSymbol::kind_property;
          $s->klass = $klass;
          $s->member_name = $local_name;
        } else {
          $this->fire_yaml_error("can't find class field $c_fqn::\$$local_name (in '$section')");
        }
        return;
      }
      // C::static_method() or C::instance_method()
      if ($v[strlen($v) - 1] === ')') {
        $local_name = substr($v, $posCol + 2, strlen($v) - $posCol - 4);
        if ($klass->hasMethod($local_name)) {
          $s->kind = ModuliteSymbol::kind_method;
          $s->klass = $klass;
          $s->member_name = $local_name;
        } else {
          $this->fire_yaml_error("can't find method $c_fqn::$local_name() (in '$section')");
        }
        return;
      }
      // C::CONST
      $local_name = substr($v, $posCol + 2);
      if ($klass->hasConstant($local_name)) {
        $s->kind = ModuliteSymbol::kind_constant;
        $s->klass = $klass;
        $s->member_name = $local_name;
      } else {
        $this->fire_yaml_error("can't find constant $c_fqn::$local_name (in '$section')");
      }
    }
  }

  // for @msg/channels/infra and @msg/folders, lca is @msg
  // for @msg/channels and @msg, lca is @msg
  // for @msg and @feed, lca is null
  function find_lca_with(ModuliteData $another_m): ?ModuliteData {
    $lca = $this;
    for (; $lca && $lca !== $another_m; $lca = $lca->parent) {
      $is_common = str_starts_with($another_m->modulite_name, $lca->modulite_name) && $another_m->modulite_name[strlen($lca->modulite_name)] === '/';
      if ($is_common) {
        break;
      }
    }
    return $lca;
  }


  // having SomeClass or someFunction(), detect a modulite this symbol belongs to
  private static function get_modulite_of_symbol(ModuliteSymbol $s, ModuliteService $service): ?ModuliteData {
    switch ($s->kind) {
      case ModuliteSymbol::kind_modulite:
        return $s->modulite->parent;
      case ModuliteSymbol::kind_klass:
      case ModuliteSymbol::kind_property:
      case ModuliteSymbol::kind_constant:
      case ModuliteSymbol::kind_method:
        return $service->detector->detectModuliteOfClass($s->klass);
      case ModuliteSymbol::kind_function:
        return $service->detector->detectModuliteOfFunction($s->function);
      case ModuliteSymbol::kind_global_const:
        return $service->detector->detectModuliteOfGlobalConst($s->constant);
      default:
        return null;
    }
  }

  function validate_yaml_requires(ModuliteService $service): void {
    foreach ($this->require as $r) {
      if ($r->kind === ModuliteSymbol::kind_modulite) {
        // @msg requires @feed or @some/another
        // valid: @feed has no parent or @some/another is exported from @some
        // invalid: it's not exported (same with longer chains, e.g. @p/c1/c2/c3, but @p/c1/c2 not exported from @p/c1)
        // valid: @api requires @api/internal, @msg/channels/infra requires @msg/internal (because they are scoped by lca)
        $another_m = $r->modulite;
        if ($this === $another_m) {
          $this->fire_yaml_error("a modulite lists itself in 'require': $this->modulite_name");
        }

        $common_lca = $this->find_lca_with($another_m);
        for ($child = $another_m; $child !== $common_lca && $child->parent !== $common_lca; $child = $child->parent) {
          $cur_parent = $child->parent;
          if ($cur_parent && !$child->exported_from_parent) {
            // valid: even if @some/another is not exported, but mentioned in "allow-internal-access" for @msg in @some
            $it_this = array_filter($cur_parent->allow_internal, function($tup) {
              /** @var ModuliteSymbol $s */
              $s = $tup[0];
              return $s->kind === ModuliteSymbol::kind_modulite && $s->modulite === $this;
            });
            if (!empty($it_this)) {
              $lists_cur = array_filter($it_this[0][1], fn(ModuliteSymbol $s) => $s->kind === ModuliteSymbol::kind_modulite && $s->modulite === $child);
              if (!empty($lists_cur)) {
                continue;
              }
            }
            $this->fire_yaml_error("can't require $another_m->modulite_name: $child->modulite_name is internal in {$child->parent->modulite_name}");
          }
        }

      } else {
        // @msg requires SomeClass / someFunction() / A::someMethod() / A::CONST / etc.
        // valid: it's in a global scope
        // invalid: it's in @some-modulite (@msg should require @some-modulite, not its symbols)
        $of_modulite = $this->get_modulite_of_symbol($r, $service);
        $is_global_scope = $of_modulite === null || ($of_modulite->is_composer_package && $of_modulite->composer_json === $this->composer_json);
        if (!$is_global_scope) {
          $this->fire_yaml_error("'require' contains a member of $of_modulite->modulite_name; you should require $of_modulite->modulite_name directly, not its members");
        }
      }
    }
  }

  function validate_yaml_exports(ModuliteService $service): void {
    foreach ($this->exports as $e) {
      if ($e->kind === ModuliteSymbol::kind_modulite) {
        // @msg exports @another_m
        // valid: @msg exports @msg/channels
        // invalid: @msg exports @feed, @msg exports @msg/channels/infra
        $another_m = $e->modulite;

        if ($this === $another_m) {
          $this->fire_yaml_error("'export' of $this->modulite_name lists itself");
        }
        if ($another_m->parent !== $this) {
          $this->fire_yaml_error("'export' of $this->modulite_name lists a non-child $another_m->modulite_name");
        }

      } else if ($e->kind !== ModuliteSymbol::kind_ref_stringname) {
        // @msg exports SomeClass / someFunction() / A::someMethod() / A::CONST / etc.
        // valid: it belongs to @msg
        // invalid: it's in a global scope, or belongs to @feed, or @msg/channels, or etc.
        if ($this !== $this->get_modulite_of_symbol($e, $service)) {
          $this->fire_yaml_error("'export' contains '$e->ref_stringname' that does not belong to $this->modulite_name");
        }
      }
    }
  }

  function validate_yaml_force_internal(ModuliteService $service): void {
    foreach ($this->force_internal as $fi) {
      // if @msg force internals A::someMethod() or A::CONST,
      // it can't be also declared in exports
      foreach ($this->exports as $e) {
        if ($e->kind === $fi->kind && $e->ref_stringname === $fi->ref_stringname && $e->member_name === $fi->member_name && $e->klass === $fi->klass) {
          $this->fire_yaml_error("'force-internal' contains '$e->ref_stringname' which is exported");
        }
      }

      $is_allowed = ($fi->kind === ModuliteSymbol::kind_constant && $fi->klass !== null)
        || ($fi->kind === ModuliteSymbol::kind_method && $fi->klass !== null)
        || ($fi->kind === ModuliteSymbol::kind_property && $fi->klass !== null);
      if (!$is_allowed) {
        // @msg force internals @msg/channels or #vk/common or SomeClass
        $this->fire_yaml_error("'force-internal' can contain only class members");
      } else {
        // @msg force internals A::someMethod() / A::CONST / etc.
        // valid: it belongs to @msg
        // invalid: it's in a global scope, or belongs to @feed, or @msg/channels, or etc.
        if ($this !== $this->get_modulite_of_symbol($fi, $service)) {
          $this->fire_yaml_error("'force-internal' contains '$fi->ref_stringname' that does not belong to $this->modulite_name");
        }
      }
    }
  }

  public function hasErrors(): bool {
    return !empty($this->errors);
  }

  public function getCollectedErrors(): array {
    return $this->errors;
  }

  // parse composer.json and emit ModulitePtr from it
  // composer packages are implicit modulites named "#"+json->name
  // if a folder also contains .modulite.yaml, it's also parsed and can manually declare "export"
  static function create_from_composer_json(ComposerJsonData $composer_json, bool $has_modulite_yaml_also): ModuliteData {
    $out = new ModuliteData();
    $out->yaml_filename = $composer_json->json_filename;
    $out->modulite_name = "#" . $composer_json->package_name;
    $out->composer_json = $composer_json;
    $out->is_composer_package = true;

    // json->require (dependent packages, e.g. "vk/utils") are copied to modulite->require, e.g. "#vk/utils"
    foreach ($composer_json->require as $package) {
      $s = new ModuliteSymbol();
      $s->kind = ModuliteSymbol::kind_ref_stringname;
      $s->ref_stringname = '#' . $package;
      $out->require[] = $s;
    }

    if ($has_modulite_yaml_also) {
      $yaml_filename = dirname($composer_json->json_filename) . '/.modulite.yaml';
      try {
        $y_file = YamlParserNoSymfony::parseFromFile($yaml_filename);
        $parser = new ModuliteYamlParser($out);
        $parser->parse_modulite_yaml_file($y_file);
      } catch (YamlParserNoSymfonyException $ex) {
        $out->fire_yaml_error($ex->getMessage(), $ex->getLine());
      }
    }

    return $out;
  }

  // parse modulite.yaml and emit ModuliteData from it
  static function create_from_modulite_yaml(string $yaml_filename, ?ModuliteData $parent): ModuliteData {
    $out = new ModuliteData();
    $out->yaml_filename = $yaml_filename;
    $out->parent = $parent;
    $out->composer_json = $parent ? $parent->composer_json : null;
    $out->is_composer_package = false;

    try {
      $y_file = YamlParserNoSymfony::parseFromFile($out->yaml_filename);
      $parser = new ModuliteYamlParser($out);
      $parser->parse_modulite_yaml_file($y_file);
    } catch (YamlParserNoSymfonyException $ex) {
      $out->fire_yaml_error($ex->getMessage(), $ex->getLine());
    }

    return $out;
  }
}
