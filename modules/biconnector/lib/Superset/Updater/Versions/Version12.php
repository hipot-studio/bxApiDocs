<?php

namespace Bitrix\BIConnector\Superset\Updater\Versions;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Main\Result;

/**
 * Registers the recovery listener on portals installed before the self-recovery feature.
 *
 * Portals already stuck in ERROR are intentionally left untouched: they join the recovery series
 * on the first user visit. Opening a series for every such portal here would turn an update into
 * a synchronous burst of startup attempts against the proxy.
 */
final class Version12 extends BaseVersion
{
	public function run(): Result
	{
		$this->registerRecoveryListener();

		return new Result();
	}

	private function registerRecoveryListener(): void
	{
		$this->registerHandlerIfNotExists(
			'biconnector',
			SupersetInitializer::EVENT_ON_AFTER_SUPERSET_STATUS_CHANGE,
			'\Bitrix\BIConnector\Integration\Superset\Recovery\StatusRecoveryListener',
			'onAfterSupersetStatusChange',
		);
	}
}
