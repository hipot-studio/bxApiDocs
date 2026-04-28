<?php

namespace Bitrix\BIConnector\Superset;

use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Rest\Marketplace\Client;

final class MarketAccessManager
{
	private static MarketAccessManager $instance;
	private bool $isRest;

	private function __construct()
	{
		$this->isRest = Loader::includeModule('rest');
	}

	public static function getInstance(): MarketAccessManager
	{
		if (!isset(self::$instance))
		{
			self::$instance = new MarketAccessManager();
		}

		return self::$instance;
	}

	public function isSubscriptionAvailable(): bool
	{
		if (!$this->isRest)
		{
			return false;
		}

		return Client::isSubscriptionAvailable();
	}

	public function getSubscriptionFinalDate(): ?Date
	{
		return $this->isRest ? Client::getSubscriptionFinalDate() : null;
	}

	public function isDashboardAvailable(int $id): bool
	{
		$dashboard = SupersetDashboardTable::getById($id)->fetchObject();

		if (!$dashboard)
		{
			return false;
		}

		$type = $dashboard->getType();

		return $this->isDashboardAvailableByType($type);
	}

	public function isDashboardAvailableByType(string $type): bool
	{
		if ($type !== SupersetDashboardTable::DASHBOARD_TYPE_MARKET)
		{
			return true;
		}

		if (!$this->isSubscriptionAccessible())
		{
			return true;
		}

		return $this->isSubscriptionAvailable();
	}

	public function isSubscriptionAccessible(): bool
	{
		if (!$this->isRest)
		{
			return false;
		}

		return Client::isSubscriptionAccess();
	}

	public function updateExpirationDate(?Date $date): bool
	{
		$result = Integrator::getInstance()->setExpirationDate($date);

		return !$result->hasErrors();
	}

	public function syncMarketDashboards(): Result
	{
		$result = new Result();

		$marketDashboardsIdList = $this->getInstalledMarketDashboardExternalIds();

		if (empty($marketDashboardsIdList))
		{
			$marketDashboardsIdList = [];
		}

		$integratorResult = Integrator::getInstance()->syncMarketDashboards($marketDashboardsIdList);

		if ($integratorResult->hasErrors())
		{
			$result->addErrors($integratorResult->getErrors());
		}

		return $result;
	}

	private function getInstalledMarketDashboardExternalIds(): array
	{
		$dashboards = SupersetDashboardTable::getList([
			'select' => ['EXTERNAL_ID'],
			'filter' => [
				'=TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_MARKET,
				'=STATUS' => SupersetDashboardTable::DASHBOARD_STATUS_READY,
				'>EXTERNAL_ID' => 0,
			],
		])
			->fetchAll()
		;

		return array_unique(array_column($dashboards, 'EXTERNAL_ID'));
	}

	public static function onRestSubscriptionRenew(): void
	{
		$manager = self::getInstance();

		if (!$manager->isSubscriptionAccessible())
		{
			$manager->updateExpirationDate(new Date('2099-12-31', 'Y-m-d'));

			return;
		}

		$finalDate = $manager->getSubscriptionFinalDate();
		if ($manager->isSubscriptionAvailable())
		{
			$manager->updateExpirationDate($finalDate);
		}
	}
}
