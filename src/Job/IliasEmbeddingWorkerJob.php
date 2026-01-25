<?php declare(strict_types=1);

namespace MissionBayIlias\Job;

use Base3\Worker\Api\IJob;
use Base3\Configuration\Api\IConfiguration;
use Base3\Database\Api\IDatabase;
use MissionBay\Api\IAgentContextFactory;
use MissionBay\Api\IAgentFlowFactory;

final class IliasEmbeddingWorkerJob implements IJob {

        private const FLOW_FILE = __DIR__ . '/../../local/Ai/embeddingflow.json';

	private const DEFAULT_PRIORITY = 5;

	private ?array $missionbayIliasConf = null;

        public function __construct(
		private readonly IConfiguration $configuration,
		private readonly IDatabase $db,
                private readonly IAgentContextFactory $contextFactory,
                private readonly IAgentFlowFactory $flowFactory
        ) {}

        public static function getName(): string {
                return 'iliasembeddingworkerjob';
        }

	public function isActive() {
		$conf = $this->getMissionbayIliasConf();
		return ((int)($conf['iliasembeddingworkerjob.active'] ?? 0)) === 1;
	}

	public function getPriority() {
		$conf = $this->getMissionbayIliasConf();
		return (int)($conf['iliasembeddingworkerjob.priority'] ?? self::DEFAULT_PRIORITY);
	}

        public function go() {
                $this->db->connect();
                if (!$this->db->connected()) {
                        return 'DB not connected';
                }

                $flowConfig = $this->loadFlowConfig();
                if (!$flowConfig) {
                        return 'Invalid embedding flow JSON';
                }

                $context = $this->contextFactory->createContext();
                $flow = $this->flowFactory->createFromArray('strictflow', $flowConfig, $context);

                try {
                        $out = $flow->run([]);
                } catch (\Throwable $e) {
                        return 'Flow execution failed: ' . $e->getMessage();
                }

                $stats = $out['embedding']['stats'] ?? [];
                $error = $out['embedding']['error'] ?? null;

                $msg = 'Worker done';

                if (is_array($stats)) {
                        $msg .= ' - items: ' . (int)($stats['num_items_done'] ?? 0)
                                . ', failed: ' . (int)($stats['num_items_failed'] ?? 0)
                                . ', chunks: ' . (int)($stats['num_chunks'] ?? 0)
                                . ', vectors: ' . (int)($stats['num_vectors'] ?? 0);
                }

                if ($error) {
                        $msg .= ' - error: ' . (string)$error;
                }

                return $msg;
        }

        // ---------------------------------------------------------
        // Helpers
        // ---------------------------------------------------------

	private function getMissionbayIliasConf(): array {
		if ($this->missionbayIliasConf === null) {
			$this->missionbayIliasConf = (array)$this->configuration->get('job');
		}
		return $this->missionbayIliasConf;
	}

        private function loadFlowConfig(): ?array {
                $json = @file_get_contents(self::FLOW_FILE);
                if (!$json) {
                        return null;
                }

                $cfg = json_decode($json, true);
                return is_array($cfg) ? $cfg : null;
        }
}
