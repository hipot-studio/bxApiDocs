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

	public function syncIfNeeded(): Main\Result
	{
		if (self::$synchronized === false && $this->isTimeToSynchronize())
		{
			self::$synchronized = true;
			return $this->sync();
		}

		return new Main\Result();
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

	protected function isTimeToSynchronize(?int $now = null): bool
	{
		$nextSyncTime = $this->configs->getNextSyncTime();
		$now = $now ?? time();
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

		$lastSyncDate = strtotime(date('Y-m-d', $lastTimeToSync));
		$nowDate = strtotime(date('Y-m-d', $now - $this->configs->getSyncDelta()));

		return $lastSyncDate < $nowDate;
	}
}
