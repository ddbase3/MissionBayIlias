<?php declare(strict_types=1);

namespace MissionBayIlias;

use Base3\Api\ICheck;
use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use Base3\Database\Api\IDatabase;
use Base3\Logger\Api\ILogger;
use Base3\Logger\ScopedDatabaseLogger\ScopedDatabaseLogger;
use MissionBay\Api\IAgentRagPayloadNormalizer;
use MissionBayIlias\Agent\IliasAgentRagPayloadNormalizer;

class MissionBayIliasPlugin implements IPlugin, ICheck {

        public function __construct(private readonly IContainer $container) {}

        // Implementation of IBase

        public static function getName(): string {
                return "missionbayiliasplugin";
        }

        // Implementation of IPlugin

        public function init() {
                $this->container
			->set(self::getName(), $this, IContainer::SHARED)
			->set(Ilogger::class, fn($c) => new ScopedDatabaseLogger($c->get(IDatabase::class)), IContainer::SHARED)
                        ->set(IAgentRagPayloadNormalizer::class, fn() => new IliasAgentRagPayloadNormalizer(), IContainer::SHARED);
        }

        // Implementation of ICheck

        public function checkDependencies() {
                return array(
                        'missionbayplugin_installed' => $this->container->get('missionbayplugin') ? 'Ok' : 'missionbayplugin not installed'
                );
        }
}
