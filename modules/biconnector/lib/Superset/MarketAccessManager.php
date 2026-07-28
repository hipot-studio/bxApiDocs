<?php

namespace Bitrix\BIConnector\Superset;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Rest\Marketplace\Client;

final class MarketAccessManager
{
	private static MarketAccessManager $instance;
	private bool $isRest;

	private const OPTION_EXPECTED_EXPIRATION = '~market_subscription_expected_expiration';
	private const OPTION_SYNCED_EXPIRATION = '~market_subscription_synced_expiration';
	private const LOCK_KEY = 'biconnector_market_subscription_expiration_sync';

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
		return $this->sendExpirationDate($date)->isSuccess();
	}

	public function syncMarketDashboards(): Result
	{
		$result = new Result();

		$marketDashboardsIdList = $this->getInstalledMarketDashboardExternalIds();

		if (empty($marketDashboardsIdList))
		{
			$marketDashboardsIdList = [];
		}

		$integratorResult = IntegratorFactory::getInstance()->syncMarketDashboards($marketDashboardsIdList);

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

	public function rememberActualExpirationDate(): void
	{
		$date = !$this->isSubscriptionAccessible()
			? new Date('2099-12-31', 'Y-m-d')
			: $this->getSubscriptionFinalDate();

		Option::set('biconnector', self::OPTION_EXPECTED_EXPIRATION, (string)($date?->getTimestamp() ?? 0));
	}

	public function syncPendingExpirationDate(): void
	{
		if (!Application::getConnection()->lock(self::LOCK_KEY, 0))
		{
			return;
		}

		try
		{
			$expected = (int)Option::get('biconnector', self::OPTION_EXPECTED_EXPIRATION, '0');
			$synced = (int)Option::get('biconnector', self::OPTION_SYNCED_EXPIRATION, '-1');

			if ($expected === $synced)
			{
				return;
			}

			$date = $expected > 0 ? Date::createFromTimestamp($expected) : Date::createFromTimestamp(24 * 60 * 60);
			$result = $this->sendExpirationDate($date);

			if ($result->isSuccess())
			{
				Option::set('biconnector', self::OPTION_SYNCED_EXPIRATION, (string)$expected);
			}

			return;
		}
		finally
		{
			Application::getConnection()->unlock(self::LOCK_KEY);
		}
	}

	private function sendExpirationDate(?Date $date): Result
	{
		$result = new Result();
		$response = IntegratorFactory::getInstance()->setExpirationDate($date);

		if ($response->hasErrors())
		{
			$result->addErrors($response->getErrors());
		}

		if ($response->getStatus() !== IntegratorResponse::STATUS_OK)
		{
			$result->addError(new Error('Unexpected Superset subscription status: ' . $response->getStatus()));
		}

		return $result;
	}

	public function actualizeSubscriptionExpirationDate(): void
	{
		$this->rememberActualExpirationDate();

		$this->syncPendingExpirationDate();
	}

	public static function onAfterSupersetStatusChange(Event $event): void
	{
		if ($event->getParameter('status') !== SupersetInitializer::SUPERSET_STATUS_READY)
		{
			return;
		}

		Application::getInstance()->addBackgroundJob(static function (): void {
			self::getInstance()->syncPendingExpirationDate();
		});
	}

	public static function onRestSubscriptionRenew(): void
	{
		Application::getInstance()->addBackgroundJob(static function (): void {
			self::getInstance()->actualizeSubscriptionExpirationDate();
		});
	}
}
