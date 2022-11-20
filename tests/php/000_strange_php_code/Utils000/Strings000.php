<?php

namespace Utils000;

class Strings000 {
  static function concat(string $s1, string $s2): string {
    return $s1 . $s2;
  }

  static function someStrangePhpCode(): void {
    $class_name = 'A';
    $method_name = 'm';
    new $class_name;
    $method_name();
    $class_name::$method_name();
    $f = null;
    $f instanceof $class_name;
    /** @var III $c */
    $c = new UnknownClass;
    $nc = new class {
      function __construct() {
      }
    };
    $gg = 'name';
    global $$gg;
    Strings000::UNKNOWN_CONST;
    Strings000::$unknown_field;
    Strings000::$$$ggg;
    $$ggg();
  }
}

