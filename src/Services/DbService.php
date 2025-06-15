<?php

namespace Hakam\MultiTenancyBundle\Services;


use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Hakam\MultiTenancyBundle\Doctrine\ORM\TenantEntityManager;
use Hakam\MultiTenancyBundle\Enum\DatabaseStatusEnum;
use Hakam\MultiTenancyBundle\Enum\DriverTypeEnum;
use Hakam\MultiTenancyBundle\Event\SwitchDbEvent;
use Hakam\MultiTenancyBundle\Exception\MultiTenancyException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

/**
 * @author Mark Ogilvie <m.ogilvie@parolla.ie>
 */
class DbService
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly TenantEntityManager      $tenantEntityManager,
        private readonly EntityManagerInterface   $entityManager,
        #[Autowire('%hakam.tenant_db_list_entity%')]
        private readonly string                   $tenantDbListEntity,
        #[Autowire('%hakam.tenant_db_credentials%')]
        private array                             $dbCredentials,
        private string                            $databaseURL,
    )
    {
    }

    /**
     * Creates a new database with the given name.
     *
     * @param TenantDbConfigurationInterface $dbConfiguration
     * @return int
     * @throws Exception If the database already exists or cannot be created.
     * @throws MultiTenancyException If the database already exists or cannot be created.
     */

    private function parseDatabaseUrl(): array
    {
        $url = parse_url($this->databaseURL);
        return [
            'dbname' => substr($url['path'], 1),
            'user' => $url['user'],
            'password' => $url['pass'],
            'host' => $url['host'],
            'port' => $url['port'],
        ];
    }

    public function getDsnUrl(): string
    {
        $dbDriver = DriverTypeEnum::SQLSRV->value;
        $dbHost = $this->parseDatabaseUrl()['host'];
        $dbPort = $this->parseDatabaseUrl()['port'];
        $dbUsername = $this->parseDatabaseUrl()['user'];
        $dbPassword = $this->parseDatabaseUrl()['password'];

        return sprintf('%s://%s:%s@%s:%s', $dbDriver, $dbUsername, $dbPassword, $dbHost, $dbPort);
    }
    public function createDatabase(TenantDbConfigurationInterface $dbConfiguration): int
    {
        $dsnParser = new DsnParser([
            'mysql' => 'pdo_mysql',
            'postgresql' => 'pdo_pgsql',
            'sqlsrv' => 'sqlsrv'
            ]);
        $tenantConnection = DriverManager::getConnection($dsnParser->parse($this->getDsnUrl()));
        try {
            $schemaManager = method_exists($tenantConnection, 'createSchemaManager')
                ? $tenantConnection->createSchemaManager()
                : $tenantConnection->getSchemaManager();
            $schemaManager->createDatabase($dbConfiguration->getDbName());
            $tenantConnection->close();
            return 1;

        } catch (\Exception $e) {
            throw new MultiTenancyException(sprintf('Unable to create new tenant database %s: %s', $dbConfiguration->getDbName(), $e->getMessage()), $e->getCode(), $e);
        }
    }

    /**
     * Creates a schema in the specified tenant database.
     *
     * @param int $UserDbId The tenant database ID.
     */
    public function createSchemaInDb(int $UserDbId): void
    {
        $metadata = $this->tenantEntityManager->getMetadataFactory()->getAllMetadata();

        $this->eventDispatcher->dispatch(new SwitchDbEvent($UserDbId));

        $schemaTool = new SchemaTool($this->tenantEntityManager);


        $sqls = $schemaTool->getUpdateSchemaSql($metadata);

        if (empty($sqls)) {
            return;
        }

        $schemaTool->updateSchema($metadata);
    }

    /**
     * Drops the specified database.
     *
     * @param string $dbName The name of the database to drop.
     * @throws MultiTenancyException|Exception If the database does not exist or cannot be dropped.
     */
    public function dropDatabase(string $dbName): void
    {
        $connection = $this->tenantEntityManager->getConnection();

        $params = $connection->getParams();

        $tmpConnection = DriverManager::getConnection($params);

        $schemaManager = method_exists($tmpConnection, 'createSchemaManager')
            ? $tmpConnection->createSchemaManager()
            : $tmpConnection->getSchemaManager();

        $shouldNotCreateDatabase = !in_array($dbName, $schemaManager->listDatabases());

        if ($shouldNotCreateDatabase) {
            throw new MultiTenancyException(sprintf('Database %s does not exist.', $dbName), Response::HTTP_BAD_REQUEST);
        }

        try {
            $schemaManager->dropDatabase($dbName);
        } catch (\Exception $e) {
            throw new MultiTenancyException(sprintf('Unable to create new tenant database %s: %s', $dbName, $e->getMessage()), $e->getCode(), $e);
        }

        $tmpConnection->close();
    }

    private function onboardNewDatabaseConfig(string $dbname): int
    {
        //check if db already exists
        $dbConfig = $this->entityManager->getRepository($this->tenantDbListEntity)->findOneBy(['dbName' => $dbname]);
        if ($dbConfig) {
            return $dbConfig->getId();
        }
        $newDbConfig = new   $this->tenantDbListEntity();
        $newDbConfig->setDbName($dbname);
        $this->entityManager->persist($newDbConfig);
        $this->entityManager->flush();
        return $newDbConfig->getId();
    }

    public function getListOfNotCreatedDataBases(): array
    {
        return $this->entityManager->getRepository($this->tenantDbListEntity)->findBy(['databaseStatus' => DatabaseStatusEnum::DATABASE_NOT_CREATED]);
    }

    public function getListOfNewCreatedDataBases(): array
    {
        return $this->entityManager->getRepository($this->tenantDbListEntity)->findBy(['databaseStatus' => DatabaseStatusEnum::DATABASE_CREATED]);
    }

    public function getListOfTenantDataBases(): array
    {
        return $this->entityManager->getRepository($this->tenantDbListEntity)->findBy(['databaseStatus' => DatabaseStatusEnum::DATABASE_MIGRATED]);
    }

    public function getDefaultTenantDataBase(): TenantDbConfigurationInterface
    {
        return $this->entityManager->getRepository($this->tenantDbListEntity)->findOneBy(['databaseStatus' => DatabaseStatusEnum::DATABASE_CREATED]);
    }
}
