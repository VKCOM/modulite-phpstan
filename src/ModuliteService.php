<?php

namespace ModulitePHPStan;

use ModulitePHPStan\ModuliteYaml\ComposerJsonData;
use ModulitePHPStan\ModuliteYaml\ModuliteData;
use ModulitePHPStan\ModuliteYaml\ModuliteYamlError;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\FileTypeMapper;

class ModuliteService {
  /** @var string /path/to/project/ , with root composer.json/composer.lock/vendor folder */
  private string $projectRoot;
  /** @var string /path/to/project/src/ , .modulite.yaml files are recursively located there */
  private string $srcRoot;
  /** @var ?string /path/to/project/packages/ , is case composer packages are written in the same repo */
  private ?string $additionalPackagesRoot = null;
  /** @var ?string /path/to/project/vendor/ , auto-calculated, composer.json files will be searched there */
  private ?string $vendorDir = null;
  /** @var SrcDir[] Dirs with modulites inside, sorted from short to long (so that a parent appears first) */
  private array $allDirsWithModulites = [];
  /** @var string[] [dir_outside_vendor/ => dir_in_vendor/] */
  private array $composerDirsOutsideMapToVendor = [];

  /** @var ModuliteData[] [name => ModuliteData] */
  private array $allModulites = [];

  /** @var ModuliteYamlError[] if there were errors initing/loading/resolving yaml */
  private array $allYamlErrors = [];
  /** @var bool if case of initing errors, Modulite rules don't check anything; instead, errors are be dumped once */
  private bool $yamlErrorsDumpedOnce = false;

  public ReflectionProvider $reflector;
  public ModuliteCheckRules $checker;
  public FileTypeMapper $fileTypeMapper;
  public ModuliteFromReflectionDetector $detector;

  function __construct(ReflectionProvider $reflector, FileTypeMapper $fileTypeMapper, ModuliteConfiguration $configuration) {
    $errOrResult = $configuration->detectProjectRoots();
    if (is_string($errOrResult)) {
      $this->allYamlErrors[] = new ModuliteYamlError(__FILE__, "Failed to init Modulite plugin.\n$errOrResult");
      return;
    }
    list($projectRoot, $srcRoot, $additionalPackagesRoot) = $errOrResult;

    $this->projectRoot = rtrim($projectRoot) . '/';
    $this->srcRoot = rtrim($srcRoot, '/') . '/';
    $this->additionalPackagesRoot = $additionalPackagesRoot ? rtrim($additionalPackagesRoot, '/') . '/' : null;
    $this->vendorDir = $this->projectRoot . 'vendor/';
    $this->collectAllDirsWithModulitesInside();

    $this->reflector = $reflector;
    $this->fileTypeMapper = $fileTypeMapper;
    $this->detector = new ModuliteFromReflectionDetector($this->allDirsWithModulites, $this->composerDirsOutsideMapToVendor);
    $this->checker = new ModuliteCheckRules($this->detector);

    // parse all yaml files and convert them to ModuliteData
    // at this point, we don't resolve symbols, we just check syntax and nesting
    foreach ($this->allDirsWithModulites as $dir) {
      if ($modulite = $this->load_modulite_inside_dir($dir)) {
        if (!empty($modulite->modulite_name)) { // for incorrect yaml, $modulite just contains errors
          $this->registerModulite($modulite);
        }
        $this->gatherErrors($modulite);
      }
    }

    if (!empty($this->allYamlErrors)) {
      return;
    }

    // resolve symbols like SomeClass and @msg in "export"/"require"/etc.
    // until now, they are stored just as string names, they can be resolved only after all classes have been loaded
    foreach ($this->allModulites as $modulite) {
      $modulite->resolve_names_to_pointers($this);
      $this->gatherErrors($modulite);
    }

    if (!empty($this->allYamlErrors)) {
      return;
    }

    // validate symbols in yaml config, after resolving all
    // see comments inside validators for what they actually do
    foreach ($this->allModulites as $modulite) {
      $modulite->validate_yaml_requires($this);
      $modulite->validate_yaml_exports($this);
      $modulite->validate_yaml_force_internal($this);
      $this->gatherErrors($modulite);
    }
  }

  // this function is very close to load_modulite_inside_dir() in KPHP
  private function load_modulite_inside_dir(SrcDir $dir): ?ModuliteData {
    if ($dir->has_composer_json) {
      // composer packages are implicit modulites "#vendorname/packagename" ("#" + json->name)
      // for instance, if a modulite in a project calls a function from a package, it's auto checked to be required
      // by default, all symbols from composer packages are exported ("exports" is empty, see modulite-check-rules.cpp)
      // but if it contains .modulite.yaml near composer.json, that yaml can manually declared exported functions
      $composer_json = $dir->parseComposerJson();
      if (!$composer_json) {
        return null;
      }
      $modulite = ModuliteData::create_from_composer_json($composer_json, $dir->has_modulite_yaml);

      $is_root = $dir->full_dir_name === $this->projectRoot;
      if ($is_root) {  // composer.json at project root is loaded, but not stored as a modulite
        return null;
      }
      $dir->nested_files_modulite = $modulite;
      return $modulite;
    }

    if ($dir->has_modulite_yaml) {
      // parse .modulite.yaml inside a regular dir
      // find the dir up the tree with .modulite.yaml; say, dir contains @msg/channels, with_parent expected to be @msg
      $with_parent = $dir;
      while ($with_parent && $with_parent->full_dir_name !== $this->srcRoot && !$with_parent->nested_files_modulite) {
        $with_parent = $with_parent->parent_dir;
      }
      $parent = $with_parent ? $with_parent->nested_files_modulite : null;

      $modulite = ModuliteData::create_from_modulite_yaml($dir->full_dir_name . '.modulite.yaml', $parent);
      $dir->nested_files_modulite = $modulite;
      return $modulite;
    }

    // a dir doesn't contain a modulite.yaml file itself — but if it's nested into another, propagate from the above
    // - VK/Messages/      it's dir->parent_dir, already inited
    //   .modulite.yaml    @messages, already parsed
    //   - Core/           it's dir, no .modulite.yaml => assign @messages here
    if ($dir->parent_dir) {
      $dir->nested_files_modulite = $dir->parent_dir->nested_files_modulite;
    }
    return null;
  }

  private function scanDirRecursively(?string $dirname, callable $eachFileCallback): void {
    if (!$dirname || !is_dir($dirname)) {
      return;
    }

    /** @var \RecursiveIteratorIterator|\DirectoryIterator $it */
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dirname, \FilesystemIterator::FOLLOW_SYMLINKS));
    $it->rewind();
    while ($it->valid()) {
      if (!$it->isDot()) {
        $eachFileCallback($it->key());
      }
      $it->next();
    }
  }

  private function collectAllDirsWithModulitesInside() {
    $dirs_modulite_yaml = [];
    $dirs_composer_json = [];

    // search for .modulite.yaml files recursively
    // note, that for a project structure like
    // my_project/   # projectRoot = srcRoot
    //   Classes/
    //   packages/   # additionalPackagesRoot
    //   vendor/     # vendorDir
    // we exclude packages/ and vendor/ from searching, as they will be scanned separately
    $this->scanDirRecursively($this->srcRoot, function(string $filename) use (&$dirs_modulite_yaml) {
      if (str_starts_with($filename, $this->vendorDir)) {
        return;
      }
      if ($this->additionalPackagesRoot && str_starts_with($filename, $this->additionalPackagesRoot)) {
        return;
      }

      if (str_ends_with($filename, '/.modulite.yaml')) {
        $dirs_modulite_yaml[] = dirname($filename) . '/';
      }
    });

    // for repositories like
    // my_project/    # projectRoot
    //   src/         # srcRoot
    //   packages/    # additionalPackagesRoot
    // we search for composer packages here also
    $this->scanDirRecursively($this->additionalPackagesRoot, function(string $filename) use (&$dirs_modulite_yaml, &$dirs_composer_json) {
      if (str_ends_with($filename, '/.modulite.yaml')) {
        $dirs_modulite_yaml[] = dirname($filename) . '/';
      }
      if (str_ends_with($filename, '/composer.json')) {
        $composer_dir = dirname($filename) . '/';
        $dirs_composer_json[] = $composer_dir;
        if ($composer_json = ComposerJsonData::parseFromFile($composer_dir . 'composer.json')) {
          // probably, dirs from vendor are just symlinked to packages, keep this mapping
          $path_in_vendor = $this->vendorDir . $composer_json->package_name . '/';
          if (is_dir($path_in_vendor)) {
            $this->composerDirsOutsideMapToVendor[$composer_dir] = $path_in_vendor;
          }
        }
      }
    });

    // scan vendor dir recursively
    // composer packages are implicit modulites, actually, e.g. "#vk/common"
    // inside their sources, they may also use Modulite, they will be auto-prefixed, e.g. "#vk/common/@utils"
    // also, skip "vendor/" inside vendor and "tests/" inside vendor/
    // todo vendor dir is scanned every time now; need to introduce some caching
    $this->scanDirRecursively($this->vendorDir, function(string $filename) use (&$dirs_modulite_yaml, &$dirs_composer_json) {
      if (strpos($filename, '/vendor/', strlen($this->vendorDir)) !== false) {
        return;
      }
      if (strpos($filename, '/tests/', strlen($this->vendorDir)) !== false) {
        return;
      }

      if (str_ends_with($filename, '/.modulite.yaml')) {
        $dirs_modulite_yaml[] = dirname($filename) . '/';
      }
      if (str_ends_with($filename, '/composer.json')) {
        $dirs_composer_json[] = dirname($filename) . '/';
      }
    });

    // in case we have packages/vk-rpc and vendor/vk/rpc symlinked, they were traversed twice
    // exclude packages from here to avoid duplicates
    foreach ($this->composerDirsOutsideMapToVendor as $outside => $in_vendor) {
      $dirs_modulite_yaml = array_filter($dirs_modulite_yaml,
        fn($dir_yaml) => !str_starts_with($dir_yaml, $outside)
      );
      $dirs_composer_json = array_filter($dirs_composer_json,
        fn($dir_yaml) => !str_starts_with($dir_yaml, $outside)
      );
    }

    $all_dirnames = array_values(array_unique([...$dirs_modulite_yaml, ...$dirs_composer_json]));

    // now we're ready to convert dirnames to SrcDir
    $this->allDirsWithModulites = array_map(fn(string $dirname) => new SrcDir(
      $dirname,
      in_array($dirname, $dirs_modulite_yaml, true),
      in_array($dirname, $dirs_composer_json, true)
    ), $all_dirnames);

    // sort all folders from short to long
    // this gives a guarantee that a parent modulite will be inited/iterated before a child
    usort($this->allDirsWithModulites, function(SrcDir $d1, SrcDir $d2) {
      return strlen($d1->full_dir_name) - strlen($d2->full_dir_name);
    });
    // having dirs sorted, we can now manipulate them
    foreach ($this->allDirsWithModulites as $dir) {
      $dir->parent_dir = $dir->findParentDir($this->allDirsWithModulites);
    }
  }

  private function gatherErrors(ModuliteData $modulite) {
    if ($modulite->hasErrors()) {
      array_push($this->allYamlErrors, ...$modulite->getCollectedErrors());
    }
  }

  public function getModulite(string $name): ?ModuliteData {
    return $this->allModulites[$name] ?? null;
  }

  private function registerModulite(ModuliteData $modulite): void {
    if (isset($this->allModulites[$modulite->modulite_name])) {
      $modulite->fire_yaml_error("Redeclaration of modulite $modulite->modulite_name:\n- {$modulite->yaml_filename}\n- {$this->allModulites[$modulite->modulite_name]->yaml_filename}");
    }
    $this->allModulites[$modulite->modulite_name] = $modulite;
  }

  public function hasAnyYamlError(): bool {
    return !empty($this->allYamlErrors);
  }

  /** @return ModuliteYamlError[] */
  public function dumpAllYamlErrors(): array {
    if ($this->yamlErrorsDumpedOnce === false) {
      $this->yamlErrorsDumpedOnce = true;
      return $this->allYamlErrors;
    }
    return [];
  }
}
