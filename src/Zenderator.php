<?php

namespace Zenderator;

use Camel\CaseTransformer;
use Camel\Format;
use Gone\Twig\InflectionExtension;
use Gone\Twig\TransformExtension;
use GuzzleHttp\Client;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use Gone\AppCore\App;
use Gone\AppCore\DbConfig;
use Gone\AppCore\Router\Router;
use Slim\Http\Environment;
use Slim\Http\Headers;
use Slim\Http\Request;
use Slim\Http\RequestBody;
use Slim\Http\Response;
use Slim\Http\Uri;
use Zend\Db\Adapter\Adapter;
use Zend\Db\Adapter\Adapter as DbAdaptor;
use Zend\Db\Metadata\Metadata;
use Zend\Db\Metadata\Object\ViewObject;
use Zend\Stdlib\ConsoleHelper;
use Zenderator\Components\Model;
use Zenderator\DataProviders\HttpProvider;
use Zenderator\Exception\Exception;
use Zenderator\Exception\SchemaToAdaptorException;
use Zenderator\Generators\PhpSdkGenerator;
use Zenderator\Generators\SwaggerGenerator;
use Zenderator\Interfaces\IZenderatorGenerator;

class Zenderator
{
    /** @var DbConfig */
    public static $databaseConfigs;
    private $rootOfApp;
    private $config; // @todo rename $composerConfig
    private $composer;
    private $namespace;
    private static $useClassPrefixes = false;
    /** @var \Twig_Loader_Filesystem */
    private $loader;
    /** @var \Twig_Environment */
    private $twig;
    /** @var Adapter[] */
    private $adapters;
    /** @var Metadata[] */
    private $metadatas;
    private $ignoredTables = [];
    /** @var CaseTransformer */
    private $transSnake2Studly;
    /** @var CaseTransformer */
    private $transStudly2Camel;
    /** @var CaseTransformer */
    private $transStudly2Studly;
    /** @var CaseTransformer */
    private $transCamel2Studly;
    /** @var CaseTransformer */
    private $transSnake2Camel;
    /** @var CaseTransformer */
    private $transSnake2Spinal;
    /** @var CaseTransformer */
    private $transCamel2Snake;

    private $vpnCheckUrl = "http://registry.segurasystems.com";

    private $waitForKeypressEnabled = true;

    private $pathsToPSR2
        = [
            APP_ROOT . "/src/AccessLayers/Base",
            APP_ROOT . "/src/AccessLayers",
            APP_ROOT . "/src/Controllers/Base",
            APP_ROOT . "/src/Controllers",
            APP_ROOT . "/src/Models/Base",
            APP_ROOT . "/src/Models",
            APP_ROOT . "/src/Validators",
            APP_ROOT . "/src/Validators/Base",
            APP_ROOT . "/src/Cleaners",
            APP_ROOT . "/src/Cleaners/Base",
            APP_ROOT . "/src/Routes",
            APP_ROOT . "/src/Services/Base",
            APP_ROOT . "/src/Services",
            APP_ROOT . "/src/*.php",
            APP_ROOT . "/tests/Api/Generated",
            APP_ROOT . "/tests/Models/Generated",
            APP_ROOT . "/public/index.php",
            APP_ROOT . "/vendor/segura/appcore",
            APP_ROOT . "/vendor/segura/zenderator",
        ];
    private $phpCsFixerRules
        = [
            '@PSR2'                               => true,
            'braces'                              => true,
            'class_definition'                    => true,
            'elseif'                              => true,
            'function_declaration'                => true,
            'array_indentation'                   => true,
            'blank_line_after_namespace'          => true,
            'lowercase_constants'                 => true,
            'lowercase_keywords'                  => true,
            'method_argument_space'               => true,
            'no_trailing_whitespace_in_comment'   => true,
            'no_closing_tag'                      => true,
            'no_php4_constructor'                 => true,
            'single_line_after_imports'           => true,
            'switch_case_semicolon_to_colon'      => true,
            'switch_case_space'                   => true,
            'visibility_required'                 => true,
            'no_unused_imports'                   => true,
            'no_useless_else'                     => true,
            'no_useless_return'                   => true,
            'no_whitespace_before_comma_in_array' => true,
            'ordered_imports'                     => true,
            'ordered_class_elements'              => true,
            'array_syntax'                        => ['syntax' => 'short'],
            'phpdoc_order'                        => true,
            'phpdoc_trim'                         => true,
            'phpdoc_scalar'                       => true,
            'phpdoc_separation'                   => true,
        ];

    private $defaultEnvironment = [];
    private $defaultHeaders = [];

    private $coverageReport;

    public function __construct(string $rootOfApp, DbConfig $databaseConfigs = null)
    {
        $this->rootOfApp = $rootOfApp;
        set_exception_handler([$this, 'exception_handler']);
        $this->setUp($databaseConfigs);

        $this->defaultEnvironment = [
            'SCRIPT_NAME' => '/index.php',
            'RAND'        => rand(0, 100000000),
        ];
        $this->defaultHeaders = [];
    }

    private function setUp(DbConfig $databaseConfigs = null)
    {
        self::$databaseConfigs = $databaseConfigs;

        $customPathsToPSR2 = [];
        if (isset($this->config['clean']) && isset($this->config['clean']['paths'])) {
            foreach ($this->config['clean']['paths'] as $path) {
                $customPathsToPSR2[] = "/app/{$path}";
            }
        }

        $this->config = self::loadConfig($this->rootOfApp);

        $this->pathsToPSR2 = array_merge($this->pathsToPSR2, $customPathsToPSR2);

        $this->composer = json_decode(file_get_contents($this->rootOfApp . "/composer.json"));
        $namespaces = array_keys((array)$this->composer->autoload->{'psr-4'});
        $this->namespace = rtrim($namespaces[0], '\\');

        $this->loader = new \Twig_Loader_Filesystem(__DIR__ . "/../generator/templates");
        $this->twig = new \Twig_Environment($this->loader, ['debug' => true]);
        $this->twig->addExtension(new \Twig_Extension_Debug());
        $this->twig->addExtension(new TransformExtension());
        $this->twig->addExtension(new InflectionExtension());

        $this->twig->addExtension(
            new \Gone\AppCore\Twig\Extensions\ArrayUniqueTwigExtension()
        );

        $fct = new \Twig_SimpleFunction('var_export', 'var_export');
        $this->twig->addFunction($fct);

        // Skip tables specified in configuration.
        if (isset($this->config['database']) && isset($this->config['database']['skip_tables'])) {
            $this->ignoredTables = $this->config['database']['skip_tables'];
        }

        $this->transSnake2Studly = new CaseTransformer(new Format\SnakeCase(), new Format\StudlyCaps());
        $this->transStudly2Camel = new CaseTransformer(new Format\StudlyCaps(), new Format\CamelCase());
        $this->transStudly2Studly = new CaseTransformer(new Format\StudlyCaps(), new Format\StudlyCaps());
        $this->transCamel2Studly = new CaseTransformer(new Format\CamelCase(), new Format\StudlyCaps());
        $this->transSnake2Camel = new CaseTransformer(new Format\SnakeCase(), new Format\CamelCase());
        $this->transSnake2Spinal = new CaseTransformer(new Format\SnakeCase(), new Format\SpinalCase());
        $this->transCamel2Snake = new CaseTransformer(new Format\CamelCase(), new Format\SnakeCase());

        // Decide if we're gonna use class prefixes. You don't want to do this if you have a single DB,
        // or you'll get classes called DefaultThing instead of just Thing.
        if (isset($this->config['database']) && isset($this->config['database']['useClassPrefixes']) && $this->config['database']['useClassPrefixes'] == true) {
            self::classPrefixesOn();
        } elseif (!is_array($databaseConfigs)) {
            self::classPrefixesOff();
        } elseif (isset($databaseConfigs['Default']) && count($databaseConfigs) == 1) {
            self::classPrefixesOff();
        } else {
            self::classPrefixesOn();
        }

        if ($databaseConfigs instanceof DbConfig) {
            foreach ($databaseConfigs->__toArray() as $dbName => $databaseConfig) {
                $this->adapters[$dbName] = new \Gone\AppCore\Adapter($databaseConfig);
                $this->metadatas[$dbName] = new Metadata($this->adapters[$dbName]);
                $this->adapters[$dbName]->query('set global innodb_stats_on_metadata=0;');
            }
        }
        return $this;
    }

    private function addCleanPath($path){
        $path = APP_ROOT ."/" . $path;
        if(!in_array($path,$this->pathsToPSR2)){
            $this->pathsToPSR2[] = $path;
        }
    }

    public function exception_handler($exception)
    {
        // UHOH exception handler
        /** @var \Exception $exception */
        echo "\n" . ConsoleHelper::COLOR_RED;
        echo " ____ ____ ____ ____ \n";
        echo "||U |||H |||O |||H ||\n";
        echo "||__|||__|||__|||__||\n";
        echo "|/__\\|/__\\|/__\\|/__\\|\n";
        echo ConsoleHelper::COLOR_RESET . "\n\n";
        echo $exception->getMessage();
        echo "\n\n";
        echo "In {$exception->getFile()}:{$exception->getLine()}";
        echo "\n\n";
        $trace = array_map(function ($a) {
            return "{$a["file"]}: {$a["line"]}";
        }, $exception->getTrace());
        array_walk($trace, function (&$elem) {
            $highlightLocations = [
                '/app/src/',
                '/app/tests/'
            ];
            foreach ($highlightLocations as $highlightLocation) {
                if (strpos($elem, $highlightLocation) === 0) {
                    $elem = "*** {$elem}";
                }
            }
        });
        foreach ($trace as $t) {
            echo "{$t}\n";
        }
        echo "\n\n";
        exit(1);
    }

    public static function classPrefixesOn()
    {
        echo "Class prefixes ON\n";
        self::$useClassPrefixes = true;
    }

    public static function classPrefixesOff()
    {
        echo "Class prefixes OFF\n";
        self::$useClassPrefixes = false;
    }

    public static function isUsingClassPrefixes(): bool
    {
        return self::$useClassPrefixes;
    }

    public static function loadConfig($rootOfApp)
    {
        if (file_exists($rootOfApp . "/zenderator.yml")) {
            $zenderatorConfigPath = $rootOfApp . "/zenderator.yml";
        } elseif (file_exists($rootOfApp . "/zenderator.yml.dist")) {
            $zenderatorConfigPath = $rootOfApp . "/zenderator.yml.dist";
        } else {
            die("Missing Zenderator config /zenderator.yml or /zenderator.yml.dist\nThere is an example in /vendor/bin/segura/zenderator/zenderator.example.yml\n\n");
        }

        $config = file_get_contents($zenderatorConfigPath);
        $config = \Symfony\Component\Yaml\Yaml::parse($config);
        return $config;
    }

    /**
     * @return \Slim\App
     */
    public function getApp()
    {
        $instanceClass = APP_CORE_NAME;
        return $instanceClass::Instance()
            ->loadAllRoutes()
            ->getApp();
    }

    public static function schemaName2databaseName($schemaName)
    {
        foreach (self::$databaseConfigs->__toArray() as $dbName => $databaseConfig) {
            $adapter = new DbAdaptor($databaseConfig);
            if ($schemaName == $adapter->getCurrentSchema()) {
                return $dbName;
            }
        }
        throw new SchemaToAdaptorException("Could not translate {$schemaName} to an appropriate dbName");
    }

    public function sanitiseTableName($tableName)
    {
        if (isset($this->config['database']) && isset($this->config['database']['remove_prefix'])) {
            if (substr($tableName, 0, strlen($this->config['database']['remove_prefix'])) == $this->config['database']['remove_prefix']) {
                return substr($tableName, 2);
            }
        }
        return $tableName;
    }

    public static function getAutoincrementColumns(DbAdaptor $adapter, $table)
    {
        $sql = "SHOW columns FROM `{$table}` WHERE extra LIKE '%auto_increment%'";
        $query = $adapter->query($sql);
        $columns = [];

        foreach ($query->execute() as $aiColumn) {
            $columns[] = $aiColumn['Field'];
        }
        return $columns;
    }

    public function getFilesRelative($dir)
    {

        $dir .= "/";
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        $files = array();
        /** @var SplFileInfo $file */
        foreach ($rii as $file) {
            if (!$file->isDir()) {
                $fullPath = $file->getPathname();
                $files[] = explode($dir, $fullPath)[1];
            }
        }
        return $files;
    }


    public function makeZenderator($cleanByDefault = false)
    {
        list($models, $views) = $this->makeModelSchemas();
        $this->removeCoreGeneratedFiles();
        $this->makeCoreFiles($models, $views);
        if ($cleanByDefault) {
            $this->cleanCode();
        }
        return $this;
    }

    public function cleanCode()
    {
        if (is_array($this->config['formatting']) && in_array("clean", $this->config['formatting'])) {
            $this->cleanCodePHPCSFixer();
        }
        $this->cleanCodeComposerAutoloader();
        return $this;
    }

    public function cleanCodePHPCSFixer(array $pathsToPSR2 = [])
    {
        $begin = microtime(true);
        echo "Cleaning... \n";

        if (empty($pathsToPSR2)) {
            $pathsToPSR2 = $this->pathsToPSR2;
        }
        foreach ($pathsToPSR2 as $pathToPSR2) {
            echo " > {$pathToPSR2} ... ";
            if (file_exists($pathToPSR2)) {
                $this->cleanCodePHPCSFixer_FixFile($pathToPSR2, $this->phpCsFixerRules);
            } else {
                echo " [" . ConsoleHelper::COLOR_RED . "Skipping" . ConsoleHelper::COLOR_RESET . ", files or directory does not exist.]\n";
            }
        }

        $time = microtime(true) - $begin;
        echo " [Complete in " . number_format($time, 2) . "]\n";
        return $this;
    }

    public function cleanCodeComposerAutoloader()
    {
        $begin = microtime(true);
        echo "Optimising Composer Autoloader... \n";
        exec("composer dump-autoload -o");
        $time = microtime(true) - $begin;
        echo "\n[Complete in " . number_format($time, 2) . "]\n";
        return $this;
    }

    public function runTests(
        bool $withCoverage = false,
        bool $haltOnError = false,
        string $testSuite = '',
        bool $debug = false
    ): int {
        echo "Running phpunit... \n";

        if ($withCoverage && file_exists(APP_ROOT . "/build/clover.xml")) {
            $previousCoverageReport = require(APP_ROOT . "/build/coverage_report.php");
            $previousCoverage = floatval((100 / $previousCoverageReport->getReport()->getNumExecutableLines()) * $previousCoverageReport->getReport()->getNumExecutedLines());
        }

        $phpunitCommand = "" .
            "./vendor/bin/phpunit " .
            ($withCoverage ? "--coverage-php=build/coverage_report.php --coverage-text" : "--no-coverage") . " " .
            ($haltOnError ? "--stop-on-failure --stop-on-error --stop-on-warning" : "") . " " .
            ($testSuite ? "--testsuite={$testSuite}" : "") . " " .
            ($debug ? "--debug" : "");
        echo " > {$phpunitCommand}\n\n";
        $startTime = microtime(true);
        passthru($phpunitCommand, $returnCode);
        $executionTimeTotal = microtime(true) - $startTime;

        if ($withCoverage) {
            /** @var CodeCoverage $coverageReport */
            $coverageReport = require(APP_ROOT . "/build/coverage_report.php");
            $coverage = floatval((100 / $coverageReport->getReport()->getNumExecutableLines()) * $coverageReport->getReport()->getNumExecutedLines());

            printf(
                "\nComplete in %s seconds. ",
                number_format($executionTimeTotal, 2)
            );

            printf(
                "\nCoverage: There is %s%% coverage. ",
                number_format($coverage, 2)
            );

            if (isset($previousCoverage)) {
                if ($coverage != $previousCoverage) {
                    printf(
                        "This is a %s%% %s in coverage.",
                        number_format($previousCoverage - $coverage, 2),
                        $coverage > $previousCoverage ? 'increase' : 'decrease'
                    );
                } else {
                    echo "There is no change in coverage. ";
                }
            }
            echo "\n\n";
        }
        return $returnCode;
    }

    public function updateSeguraDependencies()
    {
        $composerJson = json_decode(file_get_contents(APP_ROOT . "/composer.json"), true);
        $dependencies = array_merge($composerJson['require'], $composerJson['require-dev']);
        $toUpdate = [];
        foreach ($dependencies as $dependency => $version) {
            if (substr($dependency, 0, strlen("segura/")) == "segura/") {
                $toUpdate[] = $dependency;
            }
        }
        $begin = microtime(true);
        echo "Updating Segura Composer Dependencies... \n";
        foreach ($toUpdate as $item) {
            echo " > {$item}\n";
        }
        exec("composer update " . implode(" ", $toUpdate));
        $time = microtime(true) - $begin;
        echo "\n[Complete in " . number_format($time, 2) . "]\n";
        return $this;
    }

    public function getModelFieldCustomStructure($class, $field)
    {
        return $this->getSpecificModelPropertiesConfig($class)[$field] ?? [];
    }

    public function getSpecificModelPropertiesConfig($class){
        return $this->getSpecificModelConfig($class)["properties"] ?? [];
    }

    public function getSpecificModelConfig($class)
    {
        return $this->getModelsConfig()[$class] ?? [];
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function getRoutesConfig()
    {
        return $this->getConfig()["routes"] ?? [];
    }

    public function getRouteIgnoreKeys()
    {
        return $this->getRoutesConfig()["skip_argument"] ?? [];
    }

    public function makeSwagger($outputPath = APP_ROOT, $remoteApiUri = false)
    {
        $routes = $this->getRoutes($remoteApiUri);
        $swaggerGenerator = new SwaggerGenerator($this, $outputPath, new HttpProvider($remoteApiUri, APP_NAMESPACE, APP_NAME));
        $swaggerGenerator->generate();
        return $this;
    }

    public function makeSDK($outputPath = APP_ROOT, $remoteApiUri = false, $cleanByDefault = true)
    {
        $routes = $this->getRoutes($remoteApiUri);

        $phpGenerator = new PhpSdkGenerator($this, $outputPath, new HttpProvider($remoteApiUri, APP_NAMESPACE, APP_NAME));
        $phpGenerator->generate();

        $this->removePHPVCRCassettes($outputPath);
        if ($cleanByDefault) {
            $this->cleanCode();
        }
        return $this;
    }

    public function waitForKeypress($waitMessage = "Press ENTER key to continue.")
    {
        if ($this->waitForKeypressEnabled) {
            echo "\n{$waitMessage}\n";
            return trim(fgets(fopen('php://stdin', 'r')));
        }
        return false;
    }

    public function vpnCheck()
    {
        $ch = curl_init($this->vpnCheckUrl);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $data = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpcode >= 200 && $httpcode < 300) {
            return true;
        }
        return false;
    }

    public function purgeSDK($path)
    {
        $preserveVendor = false;
        if (file_exists("{$path}/vendor")) {
            $preserveVendor = true;
            echo "Preserving vendor directory...\n";
            $this->runScript(null, "mv {$path}/vendor /tmp/vendorbak_" . date("Y-m-d_H-i-s", APP_START));
        }

        echo "Purging SDK:\n";
        $this->runScript(null, "rm -R $path; mkdir -p $path");

        if ($preserveVendor) {
            echo "Restoring vendor directory...\n";
            $this->runScript(null, "mv /tmp/vendorbak_" . date("Y-m-d_H-i-s", APP_START) . " {$path}/vendor");
        }
        return $this;
    }

    public function runSDKTests($path)
    {
        echo "Installing composer dependencies\n";
        $this->runScript($path, "composer install");

        echo "Removing stale test cache data\n";
        $this->runScript($path, "rm -f {$path}/tests/fixtures/*.cassette");

        echo "Running tests...\n";
        $testResults = $this->runScript($path, "API_HOST=api ./vendor/bin/phpunit --coverage-xml build/phpunit_coverage");
        if (stripos($testResults, "ERRORS!") !== false || stripos($testResults, "FAILURES!") !== false) {
            throw new \Exception("PHPUnit says Errors happened. Something is busted!");
        }

        if (file_exists("{$path}/build/phpunit_coverage/index.xml")) {
            $this->coverageReport = simplexml_load_file("{$path}/build/phpunit_coverage/index.xml");
        }

        echo "Tests run complete\n\n\n";

        return $this;
    }

    public function checkGitSDK($path)
    {
        if (isset($this->config['sdk']['output']['git']['repo'])) {
            echo "Preparing SDK Git:\n";
            $this->runScript(null, "ssh-keyscan -H github.com >> /root/.ssh/known_hosts");
            $this->runScript($path, "git init");
            $this->runScript($path, "git remote add origin " . $this->config['sdk']['output']['git']['repo']);
            $this->runScript($path, "git fetch --all");
            $this->runScript($path, "git checkout master");
            $this->runScript($path, "git pull origin master");
        } else {
            echo "Skipping GIT step, not configured in zenderator.yml: (sdk->output->git->repo)\n";
        }
        return $this;
    }

    public function sendSDKToGit($path)
    {
        if (isset($this->config['sdk']['output']['git']['repo'])) {
            echo "Sending SDK to Git:\n";

            if ($this->coverageReport) {
                $coverageStatement = sprintf(
                    "%s coverage",
                    $this->coverageReport->project[0]->directory[0]->totals->lines->attributes()->percent
                );
            } else {
                $coverageStatement = "No coverage available.";
            }
            if (isset($this->config['sdk']['output']['git']['author']['name']) && isset($this->config['sdk']['output']['git']['author']['email'])) {
                $this->runScript($path, "git config --global user.email \"{$this->config['sdk']['output']['git']['author']['email']}\"");
                $this->runScript($path, "git config --global user.name \"{$this->config['sdk']['output']['git']['author']['name']}\"");
            }
            $this->runScript($path, "git commit -m \"Updated PHPVCR Cassettes.\" tests/fixtures");
            $this->runScript($path, "git add tests/");
            $this->runScript($path, "git commit -m \"Updated Tests. {$coverageStatement}\" tests");
            $this->runScript($path, "git add src/");
            $this->runScript($path, "git add .gitignore");
            $this->runScript($path, "git add bootstrap.php composer.* Dockerfile phpunit.xml.dist Readme.md run-tests.sh test-compose.yml");
            $this->runScript($path, "git commit -m \"Updated Library. {$coverageStatement}\"");
            $this->runScript($path, "git push origin master");
        } else {
            echo "Skipping GIT step, not configured in zenderator.yml: (sdk->output->git->repo)\n";
        }
        return $this;
    }

    public function runSdkifier($sdkOutputPath = false, $remoteApiUri = false)
    {
        if (!$sdkOutputPath) {
            $sdkOutputPath = APP_ROOT . "/vendor/segura/lib" . strtolower(APP_NAME) . "/";
            if (isset($this->config['sdk']) && isset($this->config['sdk']['output']) && isset($this->config['sdk']['output']['path'])) {
                $sdkOutputPath = APP_ROOT . "/" . $this->config['sdk']['output']['path'];
            }
            if (isset($this->config['sdk']) && isset($this->config['sdk']['output']) && isset($this->config['sdk']['output']['absolute_path'])) {
                $sdkOutputPath = $this->config['sdk']['output']['absolute_path'];
            }
        }

        return $this
            //->purgeSDK($sdkOutputPath)
            //->checkGitSDK($sdkOutputPath)
            ->makeSDK($sdkOutputPath, $remoteApiUri, false)
            ->cleanCodePHPCSFixer([$sdkOutputPath])
            //->runSDKTests($sdkOutputPath)
            //->sendSDKToGit($sdkOutputPath)
            ;
    }

    public function runSwaggerifier($outputPath = false, $remoteApiUri = false)
    {
        if (!$outputPath) {
            $outputPath = APP_ROOT . "/vendor/segura/lib" . strtolower(APP_NAME) . "/";
            if (isset($this->config['sdk']) && isset($this->config['sdk']['output']) && isset($this->config['sdk']['output']['path'])) {
                $outputPath = APP_ROOT . "/" . $this->config['sdk']['output']['path'];
            }
        }
        echo "TODO : swagger\n";
        return $this;
        return $this->makeSwagger($outputPath, $remoteApiUri);
    }

    public function disableWaitForKeypress()
    {
        $this->waitForKeypressEnabled = false;
        return $this;
    }

    public function enableWaitForKeypress()
    {
        $this->waitForKeypressEnabled = true;
        return $this;
    }

    /**
     * @return Model[]
     */
    private function makeModelSchemas(): array
    {
        /** @var Model[] $models */
        $models = [];
        /** @var Model[] $allModels */
        $allModels = [];
        $views = [];
        if (is_array($this->adapters)) {
            foreach ($this->adapters as $dbName => $adapter) {
                echo "Adaptor: {$dbName}\n";
                /**
                 * @var $tables \Zend\Db\Metadata\Object\TableObject[]
                 */
                $tables = $this->metadatas[$dbName]->getTables();

                echo "Collecting " . count($tables) . " entities data.\n";

                foreach ($tables as $table) {

                    $oModel = Components\Model::Factory($this)
                        ->setNamespace($this->namespace)
                        ->setAdaptor($adapter)
                        ->setDatabase($dbName)
                        ->setTable($table->getName())
                        ->computeColumns($table->getColumns())
                        ->computeConstraints($table->getConstraints());
                    if (!in_array($table->getName(), $this->ignoredTables)) {
                        $models[$oModel->getClassName()] = $oModel;
                    }
                    $allModels[$oModel->getClassName()] = $oModel;
                }

                /** @var ViewObject[] $views */
                $dbViews = $this->metadatas[$dbName]->getViews();
                foreach ($dbViews as $view) {
                    if (!$this->useViewAsModel($view->getName())) {
                        continue;
                    }
                    $oView = Components\ViewModel::Factory($this)
                        ->setNamespace($this->namespace)
                        ->setAdaptor($adapter)
                        ->setDatabase($dbName)
                        ->setView($view->getName())
                        ->setConfig($this->getViewModelConfig($view->getName()));

                    /**
                     * @var  string           $modelName
                     * @var  Components\Model $oModel
                     */
                    foreach ($allModels as $modelName => $oModel) {
                        if (!in_array($modelName, $this->viewModelSubModels($view->getName()))) {
                            continue;
                        }
                        $oView->addBaseModel($oModel);
                    }


                    $views[$oView->getClassName()] = $oView;
                }
            }
        }

        // Scan for remote relations
        //\Kint::dump(array_keys($models));
        foreach ($allModels as $oModel) {
            $oModel->scanForRemoteRelations($allModels, array_diff($this->ignoredTables, $this->viewModelClassNames()));
        }

        // Check for Conflicts.
        $conflictCheck = [];
        foreach ($allModels as $oModel) {
            if (count($oModel->getRemoteObjects()) > 0) {
                foreach ($oModel->getRemoteObjects() as $remoteObject) {
                    #echo "Base{$remoteObject->getLocalClass()}Model::fetch{$remoteObject->getRemoteClass()}Object\n";
                    if (!isset($conflictCheck[$remoteObject->getLocalClass()][$remoteObject->getRemoteClass()])) {
                        $conflictCheck[$remoteObject->getLocalClass()][$remoteObject->getRemoteClass()] = $remoteObject;
                    } else {
                        $conflictCheck[$remoteObject->getLocalClass()][$remoteObject->getRemoteClass()]->markClassConflict(true);
                        $remoteObject->markClassConflict(true);
                    }
                }
            }
        }

        // Bit of Diag...
        #foreach($models as $oModel){
        #    if(count($oModel->getRemoteObjects()) > 0) {
        #        foreach ($oModel->getRemoteObjects() as $remoteObject) {
        #            echo " > {$oModel->getClassName()} has {$remoteObject->getLocalClass()} on {$remoteObject->getLocalBoundColumn()}:{$remoteObject->getRemoteBoundColumn()} (Function: {$remoteObject->getLocalFunctionName()})\n";
        #        }
        #    }
        #}

        // Finally return some models.
        return [$models, $views];
    }

    public function viewTableRemaps()
    {
        $remaps = [];
        foreach ($this->getViewModelConfigs() as $name => $config) {
            $remap = $config["name"];
            foreach ($this->viewModelSubModelData($name) as $sub => $data) {
                $remaps[$sub] = $remap;
            }
        }
        return $remaps;
    }

    public function viewModelClassNames()
    {
        $names = [];
        foreach ($this->getViewModelConfigs() as $name => $config) {
            $names[] = $config["name"];
        }
        return $names;
    }

    public function viewModelSubModels($name)
    {
        $submodels = $this->viewModelSubModelData($name);
        return empty($submodels) ? [] : array_keys($submodels);
    }

    public function viewModelSubModelData($name)
    {
        return $this->getViewModelConfig($name)["sub_models"] ?? [];
    }

    public function useViewAsModel($name)
    {
        return !empty($this->getViewModelConfig($name));
    }

    public function getViewModelConfig($name)
    {
        return $this->getViewModelConfigs()[$name] ?? [];
    }

    public function getViewModelConfigs()
    {
        return $this->getConfig()["views_as_models"] ?? [];
    }

    private function removeCoreGeneratedFiles()
    {
        $generatedPaths = [
            APP_ROOT . "/src/Controllers/Base/",
            APP_ROOT . "/src/Models/Base/",
            APP_ROOT . "/src/Routes/Generated/",
            APP_ROOT . "/src/Services/Base/",
            APP_ROOT . "/src/TableAccessLayers/Base/",
            APP_ROOT . "/tests/Api/Generated/",
            APP_ROOT . "/tests/Models/Generated/",
            APP_ROOT . "/tests/Services/Generated/",
            APP_ROOT . "/tests/",
        ];
        foreach ($generatedPaths as $generatedPath) {
            if (file_exists($generatedPath)) {
                foreach (new \DirectoryIterator($generatedPath) as $file) {
                    if (!$file->isDot() && $file->getExtension() == 'php' && strpos($file->getFilename(), "Base") === 0) {
                        unlink($file->getRealPath());
                    }
                }
            }
        }
        return $this;
    }

    /**
     * @param Model[] $models
     *
     * @return Zenderator
     */
    private function makeCoreFiles(array $models, array $views)
    {
        echo "Generating Core files for " . count($models) . " models... \n";
        $allModelData = [];
        /** @var Components\Model $model */
        foreach ($models as $model) {
            $renderData = $model->getRenderDataset();
            $allModelData[$model->getClassName()] = $renderData;
            // "Model" suite
            $this->makeCoreFilesForModel($model->getClassName(), $renderData);
        }

        foreach ($views as $view) {
            $renderData = $view->getRenderDataset();
            $allModelData[$view->getClassName()] = $renderData;
            // "Model" suite
            $this->makeCoreFilesForModel($view->getClassName(), $renderData);

        }

        // "DependencyInjector" suite
        if (!$this->skipTemplate("DependencyInjector")) {
            $this->renderToFile(
                true,
                APP_ROOT . "/src/AppContainer.php",
                "DependencyInjector/appcontainer.php.twig",
                [
                    "config"          => $this->getConfig(),
                    "skipControllers" => $this->getControllersToSkip(),
                    "models"          => array_merge($models, $views),
                ]
            );
        }
        return $this;
    }

    public function getConfigModuleCustomLocation($type){
        $type = $this->getConfig()[strtolower($type)] ?? [];
        return $type["location"] ?? null;
    }

    public function makeCoreFilesForModel($className, $renderData)
    {
        echo str_pad(" > {$className} ", 35);

        $base = __DIR__ . "/../generator/templates/Classes/";
        $templateFiles = $this->getFilesRelative($base);
        $printed = [];
        foreach ($templateFiles as $templateFile) {
            $parts = explode("/", $templateFile);
            $type = $parts[0];
            $base = strtolower($parts[1]) === "base" || strtolower($parts[1]) === "generated";
            $fname = explode(".", array_pop($parts));
            array_pop($fname);
            $fname = implode(".", $fname);
            $customPath = $this->getConfigModuleCustomLocation($type);
            $file = APP_ROOT . "/src/" . implode("/", $parts) . "/";
            if(!empty($customPath)){
                $this->addCleanPath($customPath);
                $this->addCleanPath($customPath . "/Base");
                $file = APP_ROOT . "/" . $customPath . "/" . implode("/",array_slice($parts,1)) . "/";
            }
            $file .= str_replace("{classname}", $className, $fname);
            if (!isset($printed[$type])) {
                print str_pad("  | {$type}  ", 20);
            }
            if (!$this->skipTemplate($type) && !$this->skipTemplateForClass($type, $className)) {
                if (!isset($printed[$type])) {
                    print "YES  ";
                }
                $this->renderToFile($base, $file, "Classes/{$templateFile}", $renderData);
            } else {
                if (!isset($printed[$type])) {
                    print "NO   ";
                }
            }
            $printed[$type] = true;
        }
        print "\n";
        return;

        #\Kint::dump($model->getRenderDataset());
        if (!$this->skipTemplate("Models") && !$this->skipModel($className)) {
            $this->renderToFile(true, APP_ROOT . "/src/Models/Base/Base{$className}Model.php", "Models/basemodel.php.twig", $renderData);
            $this->renderToFile(false, APP_ROOT . "/src/Models/{$className}Model.php", "Models/model.php.twig", $renderData);
            $this->renderToFile(true, APP_ROOT . "/tests/Models/Generated/{$className}Test.php", "Models/tests.models.php.twig", $renderData);
            $this->renderToFile(true, APP_ROOT . "/src/AccessLayers/Base/Base{$className}AccessLayer.php", "Models/basetable.php.twig", $renderData);
            $this->renderToFile(false, APP_ROOT . "/src/AccessLayers/{$className}AccessLayer.php", "Models/table.php.twig", $renderData);
        }

        // "Service" suite
        if (!$this->skipTemplate("Services") && !$this->skipService($className)) {
            $this->renderToFile(true, APP_ROOT . "/src/Services/Base/Base{$className}Service.php", "Services/baseservice.php.twig", $renderData);
            $this->renderToFile(false, APP_ROOT . "/src/Services/{$className}Service.php", "Services/service.php.twig", $renderData);
            $this->renderToFile(true, APP_ROOT . "/tests/Services/Generated/{$className}Test.php", "Services/tests.service.php.twig", $renderData);
        }

        // "Controller" suite
        if (!$this->skipTemplate("Controllers") && !$this->skipController($className)) {
            $this->renderToFile(true, APP_ROOT . "/src/Controllers/Base/Base{$className}Controller.php", "Controllers/basecontroller.php.twig", $renderData);
            $this->renderToFile(false, APP_ROOT . "/src/Controllers/{$className}Controller.php", "Controllers/controller.php.twig", $renderData);
        }

        // "Endpoint" test suite
        if (!$this->skipTemplate("Endpoints")) {
            $this->renderToFile(true, APP_ROOT . "/tests/Api/Generated/{$className}EndpointTest.php", "ApiEndpoints/tests.endpoints.php.twig", $renderData);
        }

        // "Routes" suite
        if (!$this->skipTemplate("Routes") && !$this->skipRoute($className)) {
            $this->renderToFile(true, APP_ROOT . "/src/Routes/Generated/{$className}Route.php", "Router/route.php.twig", $renderData);
        }
    }

    public function skipTemplateForClass($template, $class)
    {
        switch ($template) {
            case "Models":
            case "AccessLayers":
                return $this->skipModel($class);
            case "Services":
                return $this->skipService($class);
            case "Controllers":
                return $this->skipController($class);
            case "Routes":
                return $this->skipRoute($class);
            default:
                return false;
        }
    }

    private function routesSoftDeleted()
    {
        return $this->getRoutesConfig()["soft_deleted"] ?? false;
    }

    private function softDeletedField()
    {
        return $this->getModelsConfig()["soft_deleted_field"] ?? null;
    }

    private function skipTemplate(string $template): bool
    {
        return !in_array($template, $this->getTemplateConfig());
    }

    private function getTemplateConfig()
    {
        return $this->config["templates"] ?? [];
    }

    private function skipRoute($name)
    {
        return in_array($name, $this->getRoutesToSkip());
    }

    public function getRoutesToSkip()
    {
        return $this->getRoutesConfig()["skip"] ?? [];
    }

    private function skipController($name)
    {
        return in_array($name, $this->getControllersToSkip());
    }

    private function getControllersConfig()
    {
        return $this->config["controllers"] ?? [];
    }

    private function getControllersToSkip()
    {
        return $this->getControllersConfig()["skip"] ?? [];
    }

    private function skipModel($name)
    {
        return in_array($name, $this->getModelsToSkip());
    }

    private function getModelsConfig()
    {
        return $this->config["models"] ?? [];
    }

    private function getModelsToSkip()
    {
        return $this->getModelsConfig()["skip"] ?? [];
    }

    private function skipService($name)
    {
        return in_array($name, $this->getServicesToSkip());
    }

    private function getServicesConfig()
    {
        return $this->config["services"] ?? [];
    }

    private function getServicesToSkip()
    {
        return $this->getServicesConfig()["skip"] ?? [];
    }

    private function renderToFile(bool $overwrite, string $path, string $template, array $data)
    {
        $output = $this->twig->render($template, $data);
        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        if (!file_exists($path) || $overwrite) {
            #echo "  > Writing to {$path}\n";
            file_put_contents($path, $output);
        }
        return $this;
    }

    private function removePHPVCRCassettes($outputPath)
    {
        if (file_exists($outputPath . "/tests/fixtures")) {
            $cassettesDir = new \DirectoryIterator($outputPath . "/tests/fixtures/");
            foreach ($cassettesDir as $cassette) {
                if (!$cassette->isDot()) {
                    if (substr($cassette->getFilename(), -9, 9) == '.cassette') {
                        unlink($cassette->getPathname());
                    }
                }
            }
        }
        return $this;
    }

    private function cleanCodePHPCSFixer_FixFile($pathToPSR2, $phpCsFixerRules)
    {
        ob_start();
        $command = APP_ROOT . "/vendor/bin/php-cs-fixer fix -q --allow-risky=yes --cache-file=/tmp/php_cs_fixer.cache --rules='" . json_encode($phpCsFixerRules) . "' {$pathToPSR2}";
        echo " > {$pathToPSR2} ... ";
        $begin = microtime(true);
        //echo $command."\n\n";
        system($command, $junk);
        //exit;
        $time = microtime(true) - $begin;
        ob_end_clean();
        echo " [" . ConsoleHelper::COLOR_GREEN . "Complete" . ConsoleHelper::COLOR_RESET . " in " . number_format($time, 2) . "]\n";

        return $this;
    }

    private function getRoutes($remoteApiUri = false)
    {
        if ($remoteApiUri) {
            echo " > Getting routes from \"{$remoteApiUri}\"";
            $client = new Client([
                'base_uri' => $remoteApiUri,
                'timeout'  => 30.0,
                'headers'  => [
                    'Accept' => 'application/json'
                ]
            ]);
            try {
                $result = $client->get("/v1")->getBody()->getContents();
            } catch (\Exception $e) {
                var_dump(get_class($e));
                die();
            }
            $body = json_decode($result, true);
            echo "Found " . count($body['Routes']) . " routes.\n";
            return $body['Routes'];
        }
        $response = $this->makeRequest("GET", "/v1");
        $body = (string)$response->getBody();
        $body = json_decode($body, true);
        echo "Found " . count($body['Routes']) . " routes.\n";
        if (empty($body['Routes'])) {
            die("Cannot find any routes while building SDK. Something has gone very wrong.\n\n");
        }
        return $body['Routes'];
    }

    /**
     * @param string $method
     * @param string $path
     * @param array  $post
     * @param bool   $isJsonRequest
     *
     * @return Response
     */
    private function makeRequest(string $method, string $path, $post = null, $isJsonRequest = true)
    {
        /**
         * @var \Slim\App         $app
         * @var \Gone\AppCore\App $applicationInstance
         */
        $applicationInstance = App::Instance();
        $calledClass = get_called_class();

        if (defined("$calledClass")) {
            $modelName = $calledClass::MODEL_NAME;
            if (file_exists(APP_ROOT . "/src/Routes/{$modelName}Route.php")) {
                require(APP_ROOT . "/src/Routes/{$modelName}Route.php");
            }
        } else {
            if (file_exists(APP_ROOT . "/src/Routes.php")) {
                require(APP_ROOT . "/src/Routes.php");
            }
        }
        if (file_exists(APP_ROOT . "/src/RoutesExtra.php")) {
            require(APP_ROOT . "/src/RoutesExtra.php");
        }
        if (file_exists(APP_ROOT . "/src/Routes") && is_dir(APP_ROOT . "/src/Routes")) {
            $count = $applicationInstance->addRoutePathsRecursively(APP_ROOT . "/src/Routes");
            #echo "Added {$count} route files\n";
        }

        $applicationInstance->loadAllRoutes();
        $app = $applicationInstance->getApp();


        #$app = Router::Instance()->populateRoutes($app);

        $envArray = array_merge($this->defaultEnvironment, $this->defaultHeaders);
        $envArray = array_merge($envArray, [
            'REQUEST_URI'    => $path,
            'REQUEST_METHOD' => $method,
        ]);

        $env = Environment::mock($envArray);
        $uri = Uri::createFromEnvironment($env);
        $headers = Headers::createFromEnvironment($env);

        $cookies = [];
        $serverParams = $env->all();
        $body = new RequestBody();
        if (!is_array($post) && $post != null) {
            $body->write($post);
            $body->rewind();
        } elseif (is_array($post) && count($post) > 0) {
            $body->write(json_encode($post));
            $body->rewind();
        }

        $request = new Request($method, $uri, $headers, $cookies, $serverParams, $body);
        if ($isJsonRequest) {
            $request = $request->withHeader("Content-type", "application/json");
        }
        $response = new Response();
        // Invoke app

        $response = $app->process($request, $response);
        #echo "\nRequesting {$method}: {$path} : ".json_encode($post) . "\n";
        #echo "Response: " . (string) $response->getBody()."\n";
        #exit;

        return $response;
    }

    private function runScript($path = null, $script)
    {
        $output = null;
        if ($path) {
            $execLine = "cd {$path} && " . $script;
        } else {
            $execLine = $script;
        }
        echo "Running: \n";
        echo " > {$execLine}\n";
        exec($execLine, $output);
        $output = implode("\n", $output);
        echo $output;
        return $output;
    }
}
