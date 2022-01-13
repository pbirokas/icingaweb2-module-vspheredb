<?php

namespace Icinga\Module\Vspheredb\Daemon\RpcNamespace;

use gipfl\Cli\Process;
use Icinga\Data\ConfigObject;
use Icinga\Module\Vspheredb\Application\MemoryLimit;
use Icinga\Module\Vspheredb\Daemon\DbCleanup;
use Icinga\Module\Vspheredb\Db;
use Icinga\Module\Vspheredb\DbObject\VCenter;
use Icinga\Module\Vspheredb\Polling\SyncStore\SyncStore;
use Icinga\Module\Vspheredb\SyncRelated\SyncStats;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use RuntimeException;

/**
 * Provides RPC methods
 */
class DbRunner
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var Db */
    protected $connection;

    protected $db;

    /** @var LoopInterface */
    protected $loop;

    protected $vCenters = [];

    /**
     * @var array vCenterId -> [ SyncStoreClassName => SyncStore ]
     */
    protected $vCenterSyncStores = [];

    public function __construct(LoggerInterface $logger, LoopInterface $loop)
    {
        MemoryLimit::raiseTo('1024M');
        $this->logger = $logger;
        $this->loop = $loop;
        $this->loop->addPeriodicTimer(3600, function () {
            if ($this->connection) {
                try {
                    $this->requireCleanup()->runRegular();
                } catch (\Exception $e) {
                    $this->logger->error($e->getMessage());
                }
            }
        });
    }

    /**
     * @param object $config
     * @return bool
     * @throws \Exception
     */
    public function setDbConfigRequest($config)
    {
        try {
            $this->vCenters = [];
            $this->vCenterSyncStores = [];
            $this->connect($config);
            $this->applyMigrations();
            $this->requireCleanup()->runForStartup();
            $this->setProcessReadyTitle();
        } catch (\Exception $e) {
            Process::setTitle('Icinga::vSphereDB::DB::failing');
            throw $e;
        }

        return true;
    }

    protected function setProcessReadyTitle()
    {
        Process::setTitle('Icinga::vSphereDB::DB::connected');
    }

    /**
     * @return bool
     */
    public function runDbCleanupRequest()
    {
        $this->requireCleanup()->runForStartup();
        return true;
    }

    /**
     * @return bool
     */
    public function clearDbConfigRequest()
    {
        $this->disconnect();
        return true;
    }

    /**
     * @return bool
     */
    public function hasPendingMigrationsRequest()
    {
        if ($this->connection === null) {
            throw new RuntimeException('Unable to determine migration status, have no DB connection');
        }

        return Db::migrationsForDb($this->connection)->hasPendingMigrations();
    }

    /**
     * @param int $vCenterId
     * @param array $result
     * @param string $taskLabel
     * @param string $storeClass
     * @param string $objectClass
     * @return SyncStats
     */
    public function processSyncTaskResultRequest($vCenterId, $result, $taskLabel, $storeClass, $objectClass)
    {
        Process::setTitle('Icinga::vSphereDB::DB::Storing ' . $taskLabel);

        $stats = new SyncStats($taskLabel);
        try {
            $this->requireSyncStoreForVCenterInstance($vCenterId, $storeClass)
                ->store($result, $objectClass, $stats);
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Task %s failed. %s: %s (%d)',
                $taskLabel,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }
        $this->setProcessReadyTitle();

        return $stats;
    }

    /**
     * @param $vCenterId
     * @param $class
     * @return SyncStore
     * @throws \Icinga\Exception\NotFoundError
     */
    protected function requireSyncStoreForVCenterInstance($vCenterId, $class)
    {
        $vCenter = $this->requireVCenter($vCenterId);
        if (! isset($this->vCenterSyncStores[$vCenterId])) {
            $this->vCenterSyncStores[$vCenterId] = [];
        }
        if (! isset($this->vCenterSyncStores[$vCenterId][$class])) {
            $this->vCenterSyncStores[$vCenterId][$class] = new $class(
                $this->db,
                $vCenter,
                $this->logger
            );
        }

        return $this->vCenterSyncStores[$vCenterId][$class];
    }

    /**
     * @param $id
     * @return VCenter
     * @throws \Icinga\Exception\NotFoundError
     */
    protected function requireVCenter($id)
    {
        if (! isset($this->vCenters[$id])) {
            $this->vCenters[$id] = VCenter::loadWithAutoIncId($id, $this->connection);
        }

        return $this->vCenters[$id];
    }

    protected function connect($config)
    {
        $this->logger->debug('Connecting to DB');
        try {
            $this->disconnect();
        } catch (\Exception $e) {
            // Ignore disconnection errors
        }
        $this->connection = new Db(new ConfigObject((array) $config));
        $this->db = $this->connection->getDbAdapter();
        $this->db->getConnection();
    }

    protected function disconnect()
    {
        if ($this->connection) {
            $this->connection->getDbAdapter()->closeConnection();
            $this->connection = null;
            $this->db = null;
        }
    }

    protected function requireCleanup()
    {
        if ($this->connection === null) {
            throw new RuntimeException('Cannot run DB cleanup w/o DB connection');
        }
        $c = new DbCleanup($this->connection->getDbAdapter(), $this->logger);
        Process::setTitle('Icinga::vSphereDB::DB::cleanup');
        return $c;
    }

    protected function applyMigrations()
    {
        $migrations = Db::migrationsForDb($this->connection);
        if (!$migrations->hasSchema()) {
            if ($migrations->hasAnyTable()) {
                throw new RuntimeException('DB has no vSphereDB schema and is not empty, aborting');
            }
            $this->logger->warning('Database has no schema, will be created');
        }
        if ($migrations->hasPendingMigrations()) {
            Process::setTitle('Icinga::vSphereDB::DB::migration');
            $this->logger->notice('Applying schema migrations');
            $migrations->applyPendingMigrations();
            $this->logger->notice('DB schema is ready');
        }
    }
}
