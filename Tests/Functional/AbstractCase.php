<?php
declare(strict_types=1);

namespace Cundd\Rest\Tests\Functional;

use Cundd\Rest\Authentication\UserProvider\FeUserProvider;
use Cundd\Rest\Authentication\UserProviderInterface;
use Cundd\Rest\Configuration\ConfigurationProviderInterface;
use Cundd\Rest\Configuration\TypoScriptConfigurationProvider;
use Cundd\Rest\Dispatcher;
use Cundd\Rest\Dispatcher\DispatcherInterface;
use Cundd\Rest\Handler\AuthHandler;
use Cundd\Rest\Handler\CrudHandler;
use Cundd\Rest\Http\RestRequestInterface;
use Cundd\Rest\Log\LoggerInterface as CunddLoggerInterface;
use Cundd\Rest\Tests\ClassBuilderTrait;
use Cundd\Rest\Tests\Functional\Integration\StreamLogger;
use Cundd\Rest\Tests\InjectPropertyTrait;
use Cundd\Rest\Tests\RequestBuilderTrait;
use Cundd\Rest\Tests\ResponseBuilderTrait;
use Cundd\Rest\VirtualObject\Persistence\BackendFactory;
use Cundd\Rest\VirtualObject\Persistence\BackendInterface;
use Cundd\Rest\VirtualObject\Persistence\Exception\SqlErrorException;
use Cundd\Rest\VirtualObject\Persistence\RawQueryBackendInterface;
use Doctrine\DBAL\DBALException;
use Exception;
use Nimut\TestingFramework\TestCase\FunctionalTestCase;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface as PsrLoggerInterface;
use SimpleXMLElement;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Imaging\IconRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\Container\Container;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use Webmozart\Assert\Assert;

/**
 * @method ObjectProphecy prophesize($classOrInterface = null);
 * @method void assertInternalType($expected, $actual, $message = '')
 * @method void assertEquals($expected, $actual, $message = '', ...$args)
 * @method void assertSame($expected, $actual, $message = '')
 * @method void assertEmpty($actual, $message = '')
 * @method void markTestSkipped($message = '')
 * @method void markTestIncomplete($message = '')
 * @method void assertInstanceOf($expected, $actual, $message = '')
 * @method void assertArrayHasKey($key, $array, $message = '')
 * @method void assertCount($expectedCount, $haystack, $message = '')
 * @method void assertNotEquals($expected, $actual, $message = '', ...$args)
 * @method void assertNull($actual, $message = '')
 * @method void assertFalse($condition, $message = '')
 * @method void assertTrue($condition, $message = '')
 * @method void assertNotEmpty($actual, $message = '')
 */
class AbstractCase extends FunctionalTestCase
{
    use ResponseBuilderTrait;
    use RequestBuilderTrait;
    use ClassBuilderTrait;
    use InjectPropertyTrait;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    public function setUp()
    {
        try {
            parent::setUp();
        } catch (\TYPO3\CMS\Core\Exception $exception) {
        } catch (DBALException $exception) {
        }

        $_SERVER['HTTP_HOST'] = 'rest.cundd.net';

        $this->registerAssetCache();
        $this->registerLoggerImplementation();
        $this->objectManager = $this->buildConfiguredObjectManager();
        $this->configureConfigurationProvider();
    }

    protected function tearDown()
    {
        parent::tearDown();
    }

    /**
     * Build a new request with the given URI
     *
     * @param string      $uri
     * @param string|null $format
     * @param string|null $method
     * @return RestRequestInterface
     */
    public function buildRequestWithUri($uri, $format = null, $method = null)
    {
        return RequestBuilderTrait::buildTestRequest(
            $uri,
            $method,
            [],     // $params
            [],     // $headers
            null,   // $rawBody
            null,   // $parsedBody
            $format
        );
    }

    /**
     * Imports a data set represented as XML into the test database,
     *
     * @param string $path Absolute path to the XML file containing the data set to load
     * @return void
     * @throws Exception
     */
    protected function importDataSet($path)
    {
        if (method_exists(get_parent_class($this), 'importDataSet')) {
            parent::importDataSet($path);

            return;
        }

        if (!is_file($path)) {
            throw new Exception(
                'Fixture file ' . $path . ' not found',
                1376746261
            );
        }

        $database = $this->getDatabaseBackend();
        $xml = simplexml_load_file($path);
        $foreignKeys = [];

        /** @var SimpleXMLElement $table */
        foreach ($xml->children() as $table) {
            $insertArray = [];

            /** @var SimpleXMLElement $column */
            foreach ($table->children() as $column) {
                $columnName = $column->getName();
                $columnValue = null;

                if (isset($column['ref'])) {
                    [$tableName, $elementId] = explode('#', $column['ref']);
                    $columnValue = $foreignKeys[$tableName][$elementId];
                } elseif (isset($column['is-NULL']) && ($column['is-NULL'] === 'yes')) {
                    $columnValue = null;
                } else {
                    $columnValue = (string)$table->$columnName;
                }

                $insertArray[$columnName] = $columnValue;
            }

            $tableName = $table->getName();
            try {
                $insertedId = $database->addRow($tableName, $insertArray);

                if (isset($table['id'])) {
                    $elementId = (string)$table['id'];
                    $foreignKeys[$tableName][$elementId] = $insertedId;
                }
            } catch (SqlErrorException $exception) {
                $this->markTestSkipped(
                    sprintf(
                        'Error when processing fixture file: %s. Can not insert data to table %s: %s',
                        $path,
                        $tableName,
                        $exception->getMessage()
                    )
                );
            }
        }
    }

    /**
     * @return BackendInterface|RawQueryBackendInterface
     */
    protected function getDatabaseBackend()
    {
        return BackendFactory::getBackend();
    }

    /**
     * @return ObjectManager
     */
    private function buildConfiguredObjectManager()
    {
        $this->initializeIconRegistry();

        /** @var Container $objectContainer */
        $objectContainer = GeneralUtility::makeInstance(Container::class);

        $objectContainer->registerImplementation(
            ConfigurationProviderInterface::class,
            TypoScriptConfigurationProvider::class
        );
        $objectContainer->registerImplementation(
            UserProviderInterface::class,
            FeUserProvider::class
        );
        $objectContainer->registerImplementation(
            DispatcherInterface::class,
            Dispatcher::class
        );

        return new ObjectManager();
    }

    protected function configureConfigurationProvider()
    {
        /** @var TypoScriptConfigurationProvider $configurationProvider */
        $configurationProvider = $this->objectManager->get(ConfigurationProviderInterface::class);
        $configurationProvider->setSettings(
            [
                "paths"            => [
                    // "all" => [
                    //     "path"         => "all",
                    //     "read"         => "deny",
                    //     "write"        => "deny",
                    //     "handlerClass" => CrudHandler::class,
                    // ],
                    //
                    // "document" => [
                    //     "path"  => "Document",
                    //     "read"  => "deny",
                    //     "write" => "deny",
                    // ],
                    //
                    // "auth" => [
                    //     "path"         => "auth",
                    //     "read"         => "allow",
                    //     "write"        => "allow",
                    //     "handlerClass" => AuthHandler::class,
                    // ],
                ],

                # Define words that should not be converted to singular
                "singularToPlural" => [
                    "news"        => "news",
                    "equipment"   => "equipment",
                    "information" => "information",
                    "rice"        => "rice",
                    "money"       => "money",
                    "species"     => "species",
                    "series"      => "series",
                    "fish"        => "fish",
                    "sheep"       => "sheep",
                    "press"       => "press",
                    "sms"         => "sms",
                ],
            ]
        );

        $this->configurePath(
            "all",
            [
                "path"         => "all",
                "read"         => "deny",
                "write"        => "deny",
                "handlerClass" => CrudHandler::class,
            ]
        );
        $this->configurePath(
            "document",
            [
                "path"  => "Document",
                "read"  => "deny",
                "write" => "deny",
            ]
        );
        $this->configurePath(
            "auth",
            [
                "path"         => "auth",
                "read"         => "allow",
                "write"        => "allow",
                "handlerClass" => AuthHandler::class,
            ]
        );
    }

    protected function configurePath($path, array $pathConfiguration)
    {
        Assert::string($path);
        /** @var TypoScriptConfigurationProvider $configurationProvider */
        $configurationProvider = $this->objectManager->get(ConfigurationProviderInterface::class);
        $configuration = $configurationProvider->getSettings();
        $configuration['paths'][$path] = $pathConfiguration;
        $configurationProvider->setSettings($configuration);
    }

    protected function registerLoggerImplementation()
    {
        /** @var Container $container */
        $container = GeneralUtility::makeInstance(Container::class);
        $container->registerImplementation(PsrLoggerInterface::class, StreamLogger::class);
        $container->registerImplementation(CunddLoggerInterface::class, StreamLogger::class);
    }

    private function initializeIconRegistry()
    {
        $iconRegistry = GeneralUtility::makeInstance(IconRegistry::class);

        if (!$iconRegistry->isRegistered('default-not-found')) {
            $iconRegistry->registerIcon(
                'default-not-found',
                SvgIconProvider::class,
                ['source' => 'EXT:rest/ext_icon.svg']
            );
        }
    }

    private function registerAssetCache()
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        try {
            $cacheManager->getCache('assets');
        } catch (Exception $e) {
            $cache = new VariableFrontend('assets', new NullBackend('unused'));
            $cacheManager->registerCache($cache);
        }
    }
}
