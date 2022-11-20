<?php

namespace ModulitePHPStan\ModuliteYaml;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\GlobalConstantReflection;

class ModuliteSymbol {
  const kind_ref_stringname = 'ref_stringname';   // a string representation, until being resolved
  const kind_modulite       = 'modulite';         // @feed or @msg/channels
  const kind_klass          = 'klass';            // SomeClass
  const kind_function       = 'function';         // someFunction()
  const kind_method         = 'method';           // A::someMethod()
  const kind_constant       = 'constant';         // A::CONST
  const kind_property       = 'property';         // A::$static_field
  const kind_global_const   = 'global_const';     // SOME_DEFINE or GLOBAL_CONST
  const kind_global_var     = 'global_var';       // $global_var

  public string $kind;                // const self::kind_*
  public string $ref_stringname;      // as it is in a yaml file

  public ?ModuliteData $modulite = null;              // kind_modulite
  public ?ClassReflection $klass = null;              // kind_klass, kind_method, kind_constant, kind_property
  public ?FunctionReflection $function = null;        // kind_function
  public ?GlobalConstantReflection $constant = null;  // kind_global_const
  public ?string $member_name = null;                 // kind_method, kind_constant, kind_property
  public ?string $global_name = null;                 // kind_global_const, kind_global_var

  function __toString(): string {
    switch ($this->kind) {
      case self::kind_modulite:
        return $this->modulite->modulite_name;
      case self::kind_klass:
        return $this->klass->getName();
      case self::kind_function:
        return "\\" . $this->function->getName();
      case self::kind_method:
        return $this->klass->getName() . "::$this->member_name()";
      case self::kind_constant:
        return $this->klass->getName() . "::$this->member_name";
      case self::kind_property:
        return $this->klass->getName() . "::\$$this->member_name";
      case self::kind_global_const:
        return $this->global_name;
      case self::kind_global_var:
        return "global \$$this->global_name";
      default:
        return "$this->ref_stringname ($this->kind)";
    }
  }
}
