<?php

namespace ModuliteTests;

use ModulitePHPStan\ModuliteYaml\YamlParserNoSymfony;
use ModulitePHPStan\ModuliteYaml\YamlParserNoSymfonyException;

class YamlParserNoSymfonyTestCase extends \PHPUnit\Framework\TestCase {
  function testSuccess() {
    $yaml = <<<'YAML'
name: "@utils"
namespace: "Algo101\\"
hello1: hello1
hello2: hello2 # comment
hello3: "hello3"
hello4: "hello4" # comment
"hello5": hello5
"hello 6": "hello 6"
"hello:7": "hello:7"
"hello'\"8": "hello'\"8" # comment
hello9:
hello10:            # comment

export:
  "asdf":
    - ""
  nested:
    - one
    - Export\ Class           
    - Export\ Class             # comment
    - "Export\\ Class         " # comment
    - "Export\\Class::method()" # comment    

require: "asdf"

map:
  k1: v1
  k2: 
  - v2
  k3: v3

allow-internal-access:
  - "Algo101"
YAML;
    $expected = [
      'name'                  => '@utils',
      'namespace'             => 'Algo101\\',
      'hello1'                => 'hello1',
      'hello2'                => 'hello2',
      'hello3'                => 'hello3',
      'hello4'                => 'hello4',
      'hello5'                => 'hello5',
      'hello 6'               => 'hello 6',
      'hello:7'               => 'hello:7',
      'hello\'"8'             => 'hello\'"8',
      'hello9'                => null,
      'hello10'               => null,
      'export'                => [
        'asdf'   => [''],
        'nested' => [
          'one',
          "Export\\ Class",
          "Export\\ Class",
          "Export\\ Class         ",
          "Export\\Class::method()"
        ]
      ],
      'require'               => 'asdf',
      'map' => [
        'k1' => 'v1',
        'k2' => ['v2'],
        'k3' => 'v3',
      ],
      'allow-internal-access' => ['Algo101'],
    ];

    $actual = YamlParserNoSymfony::parseFromString($yaml);
    $this->assertSame($expected, $actual);
  }

  function testError1() {
    $yaml = <<<'YAML'
name: "asdf"
- asdf
YAML;
    $this->expectException(YamlParserNoSymfonyException::class);
    $this->expectExceptionMessage("list in a strange place");
    YamlParserNoSymfony::parseFromString($yaml);
  }

  function testError2() {
    $yaml = <<<'YAML'
"asdf"
YAML;
    $this->expectException(YamlParserNoSymfonyException::class);
    $this->expectExceptionMessage("expected ':'");
    YamlParserNoSymfony::parseFromString($yaml);
  }

  function testError3() {
    $yaml = <<<'YAML'
exports:
-
YAML;
    $this->expectException(YamlParserNoSymfonyException::class);
    $this->expectExceptionMessage("expected a string");
    YamlParserNoSymfony::parseFromString($yaml);
  }
}
