<?php

declare(strict_types=1);

namespace Bitrix\Baas\Service;

use Bitrix\Baas;
use Bitrix\Main;

class BillingSynchronizationService
{
	use Baas\Internal\Trait\SingletonConstructor;

	private Baas\Config\Client $configs;
	private Baas\Service\BillingService $billingService;

	private static bool $synchronized = false;

	protected function __construct(
		?Baas\Service\BillingService $billingService = null,
		?Baas\Config\Client $configs = null,
	)
	{
		$this->billingService = $billingService ?? Baas\Service\BillingService::getInstance();
		$this->configs = $configs ?? new Baas\Config\Client();
	}

	public function syncIfNeeded(): void
	{
		if (self::$synchronized === false && $this->isTimeToSynchronize())
		{
			$this->sync();
		}
	}

	public function sync(): Main\Result
	{
		try
		{
			$syncResult = $this->billingService->synchronizeWithBilling();
		}
		catch (Baas\UseCase\BaasException $e)
		{
			$this->delaySyncForSec();
			$syncResult = new Main\Result();
			$syncResult->addError(new Main\Error($e->getMessage()));
		}

		return $syncResult;
	}

	protected function delaySyncForSec(int $sec = 300): void
	{
		$this->configs->setNextSyncTime(
			time() + $sec,
		);
	}

	protected function isTimeToSynchronize(): bool
	{
		$nextSyncTime = $this->configs->getNextSyncTime();
		$now = time();
		// If the next sync time is in the future, we should not sync
		if ($nextSyncTime > $now)
		{
			return false;
		}

		$lastTimeToSync = $this->billingService->getLastSyncTime();
		// If we have never synced, we should sync
		if ($lastTimeToSync <= 0)
		{
			return true;
		}

		$ttl = min($this->configs->getSyncInterval(), strtotime('tomorrow') + 1 - $now);

		// If the last sync time is older than the ttl, we should sync
		return ($lastTimeToSync + $ttl) < time();
	}
}
