<?php

namespace ModulitePHPStan\ModuliteYaml;

// IMPORTANT! keep this class and logic very close to ModuliteYamlParser in KPHP
class ModuliteYamlParser {
  private ModuliteData $out;

  function __construct(ModuliteData $out) {
    $this->out = $out;
  }

  static private function scalar($y_node): string {
    if (is_string($y_node)) {
      return $y_node;
    }
    return '';
  }

  private function as_string($y_node, string $section): string {
    if (!is_string($y_node) || empty($y_node)) {
      $this->fire_yaml_error("expected non-empty string (in '$section')");
      return '';
    }
    return $y_node;
  }

  private function fire_yaml_error(string $message) {
    $this->out->fire_yaml_error($message);
  }


  private function parse_yaml_name($y_name): void {
    $out = $this->out;

    $name = self::scalar($y_name);
    if (empty($name) || $name[0] !== '@') {
      if ($name === '<composer_root>') {
        return;
      }
      $this->fire_yaml_error("'name' should start with @");
    }

    $modulite_name = $out->composer_json ? self::prepend_composer_package_to_name($this->out->composer_json, $name) : $name;
    $last_slash = strrpos($modulite_name, '/');
    $expected_parent_name = $last_slash === false ? null : substr($modulite_name, 0, $last_slash);

    // valid:   @msg/channels/infra is placed in @msg/channels
    // invalid: @msg/channels/infra is placed in @msg, @feed is placed in @msg
    if (!$out->is_composer_package && $out->parent && $out->parent->modulite_name != $expected_parent_name) {
      $this->fire_yaml_error("inconsistent nesting: $modulite_name placed in {$out->parent->modulite_name}");
    }
    // invalid: @msg/channels is placed outside
    if (!$out->is_composer_package && !$out->parent && $last_slash !== false) {
      $this->fire_yaml_error("inconsistent nesting: $modulite_name placed outside of $expected_parent_name");
    }

    $out->modulite_name = $modulite_name;
  }

  private function parse_yaml_namespace($y_namespace): void {
    $out = $this->out;

    $ns = self::scalar($y_namespace);
    if (empty($ns) || $ns === "\\") {
      return;
    }

    // "\\Some\\Ns" => "Some\\Ns\\"
    $out->modulite_namespace = $ns;
    if ($out->modulite_namespace[0] === '\\') {
      $out->modulite_namespace = substr($out->modulite_namespace, 1);
    }
    if ($out->modulite_namespace[strlen($out->modulite_namespace) - 1] !== '\\') {
      $out->modulite_namespace .= '\\';
    }
  }

  // parse any symbol as a string: "\\f()", "@msg/channels", "RelativeClass", etc.
  // at the moment of parsing, they are stored as strings, and later resolved to symbols (see resolve_names_to_pointers())
  private function parse_any_scalar_symbol(string $s): ModuliteSymbol {
    $symbol = new ModuliteSymbol();
    $symbol->kind = ModuliteSymbol::kind_ref_stringname;
    $symbol->ref_stringname = $s;
    return $symbol;
  }

  private function parse_yaml_export(array $y_export): void {
    foreach ($y_export as $y) {
      $y_name = $this->as_string($y, 'export');
      $this->out->exports[] = $this->parse_any_scalar_symbol($y_name);
    }
  }

  private function parse_yaml_force_internal($y_force_internal): void {
    foreach ($y_force_internal as $y) {
      $y_name = $this->as_string($y, 'force-internal');
      $this->out->force_internal[] = $this->parse_any_scalar_symbol($y_name);
    }
  }

  private function parse_yaml_require($y_require): void {
    foreach ($y_require as $y) {
      $y_name = $this->as_string($y, 'require');
      $this->out->require[] = $this->parse_any_scalar_symbol($y_name);
    }
  }

  private function parse_yaml_allow_internal($y_allow_internal) {
    foreach ($y_allow_internal as $key => $y) {
      $y_rule = $this->as_string($key, 'allow-internal');
      if (!is_array($y)) {
        $this->fire_yaml_error("invalid format, expected a map of sequences (in 'allow-internal' $y_rule)");
        continue;
      }
      /** @var ModuliteSymbol[] $e_vector */
      $e_vector = [];
      foreach ($y as $y_rule_val) {
        $e_vector[] = $this->parse_any_scalar_symbol($this->as_string($y_rule_val, 'allow-internal ' . $y_rule));
      }
      $this->out->allow_internal[] = [$this->parse_any_scalar_symbol($y_rule), $e_vector];
    }
  }

  public function parse_modulite_yaml_file($y_file): void {
    $y_name = $y_file["name"] ?? null;
    if (is_string($y_name)) {
      $this->parse_yaml_name($y_name);
    } else {
      $this->fire_yaml_error("'name' not specified");
    }

    $y_namespace = $y_file["namespace"] ?? null;
    if (is_string($y_namespace)) {
      $this->parse_yaml_namespace($y_namespace);
    } else {
      $this->fire_yaml_error("'namespace' not specified");
    }

    $y_export = $y_file["export"] ?? null;
    if (is_array($y_export) && isset($y_export[0])) {
      $this->parse_yaml_export($y_export);
    } else if (!array_key_exists('export', $y_file)) {
      $this->fire_yaml_error("'export' not specified");
    } else if ($y_export !== null) {
      $this->fire_yaml_error("'export' has incorrect format");
    }

    $y_force_internal = $y_file["force-internal"] ?? null;
    if (is_array($y_force_internal) && isset($y_force_internal[0])) {
      $this->parse_yaml_force_internal($y_force_internal);
    } else if ($y_force_internal !== null) {
      $this->fire_yaml_error("'force-internal' has incorrect format");
    }

    $y_require = $y_file["require"] ?? null;
    if (is_array($y_require) && isset($y_require[0])) {
      $this->parse_yaml_require($y_require);
    } else if (!array_key_exists('require', $y_file)) {
      $this->fire_yaml_error("'require' not specified");
    } else if ($y_require !== null) {
      $this->fire_yaml_error("'require' has incorrect format");
    }

    $y_allow_internal = $y_file["allow-internal-access"] ?? null;
    if (is_array($y_allow_internal) && !isset($y_allow_internal[0])) {
      $this->parse_yaml_allow_internal($y_allow_internal);
    } else if (isset($y_allow_internal)) {
      $this->fire_yaml_error("'allow-internal-access' has incorrect format");
    }
  }

  // when a PHP developer writes a Composer package and uses modulites in it,
  // modulites named absolutely, like @utils or @flood-details — like in any other project
  // but when a monolith uses that package placed in vendor/, all its modulites are prefixed:
  // @flood/details in #vk/common is represented as "#vk/common/@flood/details" on monolith compilation
  static function prepend_composer_package_to_name(ComposerJsonData $composer_json, string $modulite_name): string {
    return "#" . $composer_json->package_name . "/" . $modulite_name;
  }

}
