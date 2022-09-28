<?php

namespace Icinga\Module\Vspheredb\Clicommands;

use gipfl\Cli\Screen;
use Icinga\Exception\NotFoundError;
use Icinga\Module\Vspheredb\CheckPluginHelper;
use Icinga\Module\Vspheredb\Configuration;
use Icinga\Module\Vspheredb\Daemon\ConnectionState;
use Icinga\Module\Vspheredb\Daemon\RemoteClient;
use Icinga\Module\Vspheredb\Db;
use Icinga\Module\Vspheredb\Db\CheckRelatedLookup;
use Icinga\Module\Vspheredb\DbObject\BaseDbObject;
use Icinga\Module\Vspheredb\Monitoring\CheckPluginState;
use Icinga\Module\Vspheredb\Monitoring\CheckRunner;
use Icinga\Module\Vspheredb\Monitoring\Health\ServerConnectionInfo;
use Icinga\Module\Vspheredb\Monitoring\Health\VCenterInfo;
use InvalidArgumentException;

/**
 * vSphereDB Check Command
 */
class CheckCommand extends Command
{
    use CheckPluginHelper;

    /** @var Db */
    protected $db;

    /**
     * Check vSphereDB daemon health
     */
    public function healthAction()
    {
        $this->run(function () {
            $client = new RemoteClient(Configuration::getSocketPath(), $this->loop());
            return $client->request('vsphere.getApiConnections')->then(function ($result) {
                $connState = new ConnectionState($result, $this->db()->getDbAdapter());
                $vCenters = VCenterInfo::fetchAll($this->db()->getDbAdapter());
                $connections = $connState->getConnectionsByVCenter();
                foreach ($vCenters as $vcenter) {
                    $this->checkVCenterConnection($vcenter, $connections);
                }

                if (count($vCenters) > 1) {
                    if ($this->getState() === 0) {
                        $this->prependMessage('All vCenters/ESXi Hosts are connected');
                    } else {
                        $this->prependMessage('There are problems with some vCenters/ESXi Host connections');
                    }
                }
            }, function (\Exception $e) {
                $message = $e->getMessage();
                if (preg_match('/^Unable to connect/', $message)) {
                    $message = "Daemon not running? $message";
                }
                $this->addProblem('CRITICAL', $message);
            });
        });
    }

    /**
     * @deprecated
     */
    public function vcenterconnectionAction()
    {
        $this->addProblem('UNKNOWN', 'This check no longer exists. Please use `icingacli vspheredb check health`');
    }

    /**
     * Check Host Health
     *
     * USAGE
     *
     * icingacli vspheredb check host [--name <name>]
     */
    public function hostAction()
    {
        $this->run(function () {
            $host = $this->lookup()->findOneBy('HostSystem', [
                'host_name' => $this->params->getRequired('name')
            ]);
            $this->runChecks($host);
        });
    }

    /**
     * Check all Hosts
     *
     * USAGE
     *
     * icingacli vspheredb check hosts
     */
    public function hostsAction()
    {
        $this->showOverallStatusForProblems(
            $this->lookup()->listNonGreenObjects('HostSystem')
        );
    }

    /**
     * Check Virtual Machine Health
     *
     * USAGE
     *
     * icingacli vspheredb check vm [--name <name>]
     */
    public function vmAction()
    {
        $this->run(function () {
            try {
                $vm = $this->lookup()->findOneBy('VirtualMachine', [
                    'object_name' => $this->params->getRequired('name')
                ]);
            } catch (NotFoundError $e) {
                $vm = $this->lookup()->findOneBy('VirtualMachine', [
                    'guest_host_name' => $this->params->getRequired('name')
                ]);
            }
            $this->runChecks($vm);
        });
    }

    /**
     * Check all Virtual Machines
     *
     * USAGE
     *
     * icingacli vspheredb check vms
     */
    public function vmsAction()
    {
        $this->showOverallStatusForProblems(
            $this->lookup()->listNonGreenObjects('VirtualMachine')
        );
    }

    /**
     * Check Datastore Health
     *
     * USAGE
     *
     * icingacli vspheredb check datastore [--name <name>]
     */
    public function datastoreAction()
    {
        $this->run(function () {
            $datastore = $this->lookup()->findOneBy('Datastore', [
                'object_name' => $this->params->getRequired('name')
            ]);
            $this->runChecks($datastore);
        });
    }

    /**
     * Check all Datastores
     *
     * USAGE
     *
     * icingacli vspheredb check datastores
     */
    public function datastoresAction()
    {
        $this->showOverallStatusForProblems(
            $this->lookup()->listNonGreenObjects('Datastore')
        );
    }

    protected function runChecks(BaseDbObject $object)
    {
        $runner = new CheckRunner($this->db());
        if ($section = $this->params->get(CheckRunner::RULESET_NAME_PARAMETER)) {
            self::assertString($section, '--' . CheckRunner::RULESET_NAME_PARAMETER);
            $runner->setRuleSetName($section);
        }
        if ($rule = $this->params->get(CheckRunner::RULE_NAME_PARAMETER)) {
            self::assertString($rule, '--' . CheckRunner::RULE_NAME_PARAMETER);
            $runner->setRuleName($rule);
        }
        if ($this->params->get('inspect')) {
            $runner->enableInspection();
        }
        $result = $runner->check($object);
        echo $this->colorizeOutput($result->getOutput()) . PHP_EOL;
        exit($result->getState()->getExitCode());
    }

    protected static function assertString($string, string $label)
    {
        if (! is_string($string)) {
            throw new InvalidArgumentException("$label must be a string");
        }
    }

    /**
     * @param VCenterInfo $vcenter
     * @param array<int, array<int, ServerConnectionInfo>> $connections
     * @return void
     */
    protected function checkVCenterConnection(VCenterInfo $vcenter, array $connections)
    {
        $vcenterId = $vcenter->id;
        $prefix = sprintf('%s, %s: ', $vcenter->name, $vcenter->software);
        if (isset($connections[$vcenterId])) {
            foreach ($connections[$vcenterId] as $connection) {
                if ($connection->enabled) {
                    $this->addProblem(
                        $connection->getIcingaState(),
                        $prefix . ConnectionState::describe($connection)
                    );
                } else {
                    $this->addMessage(
                        "[DISABLED] $prefix"
                        . ConnectionState::describe($connection)
                    );
                }
            }
        } else {
            $this->addProblem('WARNING', $prefix . ConnectionState::describeNoServer());
        }
    }

    protected function colorizeOutput(string $string): string
    {
        $screen = Screen::factory();
        $pattern = '/\[(OK|WARNING|CRITICAL|UNKNOWN)]\s/';
        return preg_replace_callback($pattern, function ($match) use ($screen) {
            return '[' .$screen->colorize($match[1], (new CheckPluginState($match[1]))->getColor()) . '] ';
        }, $string);
    }

    protected function showOverallStatusForProblems($problems)
    {
        $this->run(function () use ($problems) {
            if (empty($problems)) {
                $this->addMessage('Everything is fine');
            } else {
                foreach ($problems as $color => $objects) {
                    $this->raiseState($this->getStateForColor($color));
                    $this->addProblematicObjectNames($color, $objects);
                }
            }
        });
    }

    protected function addProblematicObjectNames($color, $objects)
    {
        $showMax = 5;
        $stateName = $this->getStateForColor($color);
        if (count($objects) === 1) {
            $name = array_shift($objects);
            $this->addProblem($stateName, sprintf('Overall status for %s is "%s"', $name, $color));
        } elseif (count($objects) <= $showMax) {
            $last = array_pop($objects);
            $this->addProblem($stateName, sprintf(
                'Overall status is "%s" for %s and %s',
                $color,
                implode(', ', $objects),
                $last
            ));
        } else {
            $names = array_slice($objects, 0, $showMax);
            $this->addProblem($stateName, sprintf(
                'Overall status is "%s" for %s and %d more',
                $color,
                implode(', ', $names),
                count($objects) - $showMax
            ));
        }
    }

    protected function getStateForColor($color): string
    {
        $colors = [
            'green'  => 'OK',
            'gray'   => 'CRITICAL',
            'yellow' => 'WARNING',
            'red'    => 'CRITICAL',
        ];

        return $colors[$color];
    }

    protected function lookup(): CheckRelatedLookup
    {
        return new CheckRelatedLookup($this->db());
    }

    protected function db(): Db
    {
        if ($this->db === null) {
            $this->db = Db::newConfiguredInstance();
        }

        return $this->db;
    }
}
