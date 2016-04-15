<?php
namespace Zenderator;

use Slim;
use Faker\Provider;
use Faker\Factory as FakerFactory;
use Zeuxisoo\Whoops\Provider\Slim\WhoopsMiddleware;

class App
{

    static $instance;

    /** @var \Slim\App  */
    protected $app;
    /** @var \Interop\Container\ContainerInterface */
    protected $container;

    /**
     * @return App
     */
    public static function Instance($doNotUseStaticInstance = false)
    {
        if (!self::$instance || $doNotUseStaticInstance === true) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return \Interop\Container\ContainerInterface
     */
    public static function Container()
    {
        return self::Instance()->getContainer();
    }

    /**
     * @return \Interop\Container\ContainerInterface
     */
    public function getContainer()
    {
        return $this->container;
    }

    public function getApp()
    {
        return $this->app;
    }

    public function __construct()
    {

        // Create Slim app
        $this->app = new \Slim\App(
            [
                'settings' => [
                    'debug' => true,
                    'displayErrorDetails' => true,
                ]
            ]
        );

        // Add whoops to slim because its helps debuggin' and is pretty.
        $this->app->add(new WhoopsMiddleware());

        // Fetch DI Container
        $this->container = $this->app->getContainer();

        // Register Twig View helper
        $this->container['view'] = function ($c) {
            $view = new \Slim\Views\Twig(
                '../views/',
                [
                'cache' => false,
                'debug' => true
                ]
            );

            // Instantiate and add Slim specific extension
            $view->addExtension(
                new Slim\Views\TwigExtension(
                    $c['router'],
                    $c['request']->getUri()
                )
            );

            // Added Twig_Extension_Debug to enable twig dump() etc.
            $view->addExtension(
                new \Twig_Extension_Debug()
            );

            $view->addExtension(new \Twig_Extensions_Extension_Text());

            return $view;
        };

        $this->container['DatabaseInstance'] = function (Slim\Container $c) {
            return Db::getInstance();
        };

        $this->container['Faker'] = function (Slim\Container $c) {
            $faker = FakerFactory::create();
            $faker->addProvider(new Provider\Base($faker));
            $faker->addProvider(new Provider\DateTime($faker));
            $faker->addProvider(new Provider\Lorem($faker));
            $faker->addProvider(new Provider\Internet($faker));
            $faker->addProvider(new Provider\Payment($faker));
            $faker->addProvider(new Provider\en_US\Person($faker));
            $faker->addProvider(new Provider\en_US\Address($faker));
            $faker->addProvider(new Provider\en_US\PhoneNumber($faker));
            $faker->addProvider(new Provider\en_US\Company($faker));
            return $faker;
        };

        require(APP_ROOT . "/src/AppContainer.php");
    }

    public function loadAllRoutes()
    {
        require(APP_ROOT . "/src/Routes.php");
        return $this;
    }
}