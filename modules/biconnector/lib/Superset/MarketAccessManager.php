<?php

namespace Bitrix\BIConnector\Superset;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware\Diagnostics;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\DI\ServiceLocator;
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
	private bool $isDeliveryRefused = false;

	private const OPTION_EXPECTED_EXPIRATION = '~market_subscription_expected_expiration';
	private const OPTION_SYNCED_EXPIRATION = '~market_subscription_synced_expiration';
	private const LOCK_KEY = 'biconnector_market_subscription_expiration_sync';

	/**
	 * The gate runs on every opening of a market dashboard, and the pending page reloads every 10 seconds:
	 * one attempt per interval for the whole portal, short timeouts so the page is not held for long.
	 */
	private const GATE_ATTEMPT_KEY = 'biconnector.market_subscription.gate_attempt';
	private const GATE_ATTEMPT_INTERVAL = 10;
	private const GATE_SOCKET_TIMEOUT = 5;
	private const GATE_STREAM_TIMEOUT = 10;

	/**
	 * Outlasts one delivery with the default timeouts of the connector: 30 seconds to connect, 60 to read.
	 */
	private const RENEWAL_LOCK_TIMEOUT = 120;

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

	/** @param (\Closure(?bool, bool): void)|null $observe Internal receipt of the original policy branch. */
	public function isDashboardAvailableByType(string $type, ?\Closure $observe = null): bool
	{
		if ($type !== SupersetDashboardTable::DASHBOARD_TYPE_MARKET)
		{
			$observe?->__invoke(null, false);

			return true;
		}

		$accessible = $this->isSubscriptionAccessible();
		if (!$accessible)
		{
			$observe?->__invoke($accessible, false);

			return true;
		}

		$available = $this->isSubscriptionAvailable();
		$observe?->__invoke($accessible, true);

		return $available;
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
		Option::set('biconnector', self::OPTION_EXPECTED_EXPIRATION, (string)$this->getActualExpirationTimestamp());
	}

	private function getActualExpirationTimestamp(): int
	{
		$date = !$this->isSubscriptionAccessible()
			? new Date('2099-12-31', 'Y-m-d')
			: $this->getSubscriptionFinalDate();

		return $date?->getTimestamp() ?? 0;
	}

	/**
	 * Delivers the remembered expiration date to Superset unless it is already there.
	 * A failed delivery schedules MarketSubscriptionSyncAgent, a refused one drops it: see isDeliveryRefused().
	 *
	 * @param int $socketTimeout Same meaning as in IntegratorInterface::setExpirationDate().
	 * @param int $streamTimeout Same meaning as in IntegratorInterface::setExpirationDate().
	 * @param bool $detectFrozenInstance Same meaning as in IntegratorInterface::setExpirationDate().
	 * @return bool Whether Superset has the remembered date. A delivery running in parallel
	 *              is not awaited: the answer is the state before it ends.
	 */
	public function syncPendingExpirationDate(
		int $socketTimeout = 0,
		int $streamTimeout = 0,
		bool $detectFrozenInstance = false,
	): bool
	{
		$this->isDeliveryRefused = false;

		if (!Application::getConnection()->lock(self::LOCK_KEY, 0))
		{
			return $this->isExpirationDateSynced();
		}

		try
		{
			return $this->deliverPendingExpirationDate($socketTimeout, $streamTimeout, $detectFrozenInstance);
		}
		finally
		{
			Application::getConnection()->unlock(self::LOCK_KEY);
		}
	}

	/**
	 * Whether the last syncPendingExpirationDate() ended with a refusal the service has diagnosed.
	 * The date is not delivered then, and repeating the request would not change the answer.
	 */
	public function isDeliveryRefused(): bool
	{
		return $this->isDeliveryRefused;
	}

	/**
	 * Must be called under the delivery lock.
	 */
	private function deliverPendingExpirationDate(
		int $socketTimeout = 0,
		int $streamTimeout = 0,
		bool $detectFrozenInstance = false,
	): bool
	{
		if ($this->isExpirationDateSynced())
		{
			return true;
		}

		$expected = (int)Option::get('biconnector', self::OPTION_EXPECTED_EXPIRATION, '0');
		$date = $expected > 0 ? Date::createFromTimestamp($expected) : Date::createFromTimestamp(24 * 60 * 60);
		$result = $this->sendExpirationDate($date, $socketTimeout, $streamTimeout, $detectFrozenInstance);

		if ($result->isSuccess())
		{
			Option::set('biconnector', self::OPTION_SYNCED_EXPIRATION, (string)$expected);

			return true;
		}

		if (Diagnostics::isDiagnosedRefusal($result->getErrors()))
		{
			$this->isDeliveryRefused = true;
			MarketSubscriptionSyncAgent::cancel();

			return false;
		}

		MarketSubscriptionSyncAgent::schedule();

		return false;
	}

	/**
	 * Gate in front of a market dashboard: Superset closes such a dashboard by the expiration date
	 * it knows, so the dashboard is held only while that date has passed or is unknown.
	 * Then makes one short delivery attempt at most once per GATE_ATTEMPT_INTERVAL for the portal.
	 * A frozen instance met by the attempt moves the portal to LOAD, and the waiting screen of the caller
	 * wakes the instance up the same way as for any other dashboard.
	 * While the known date lasts, the dashboard is shown and the new date is delivered in the background.
	 *
	 * @param string $dashboardType Type of the dashboard being opened.
	 * @return bool Whether the dashboard may be shown. False means the caller shows its waiting screen,
	 *              which comes back here by itself.
	 */
	public function ensureExpirationDateDelivered(string $dashboardType): bool
	{
		if ($dashboardType !== SupersetDashboardTable::DASHBOARD_TYPE_MARKET)
		{
			return true;
		}

		// Not READY: the credentials are refused anyway, so the caller behaves as before and nothing is sent.
		if (!SupersetInitializer::isSupersetReady())
		{
			return true;
		}

		// Catches up a renewal whose event was missed: the expected date follows the subscription.
		$actual = $this->getActualExpirationTimestamp();
		if ($actual !== (int)Option::get('biconnector', self::OPTION_EXPECTED_EXPIRATION, '0'))
		{
			Option::set('biconnector', self::OPTION_EXPECTED_EXPIRATION, (string)$actual);
		}

		if ($this->isExpirationDateSynced())
		{
			return true;
		}

		// Superset still shows the dashboard: a failed delivery must not close it.
		if ($this->isSyncedDateInForce())
		{
			if ($this->claimGateAttempt())
			{
				Application::getInstance()->addBackgroundJob(static function (): void {
					self::getInstance()->syncPendingExpirationDate();
				});
			}

			return true;
		}

		if (!$this->claimGateAttempt())
		{
			return false;
		}

		return $this->syncPendingExpirationDate(
			self::GATE_SOCKET_TIMEOUT,
			self::GATE_STREAM_TIMEOUT,
			detectFrozenInstance: true,
		);
	}

	/**
	 * The marker lives GATE_ATTEMPT_INTERVAL seconds: while it exists, the portal has made its attempt.
	 */
	private function claimGateAttempt(): bool
	{
		try
		{
			$storage = ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
			if ($storage->get(self::GATE_ATTEMPT_KEY) !== null)
			{
				return false;
			}

			$storage->set(self::GATE_ATTEMPT_KEY, time(), self::GATE_ATTEMPT_INTERVAL);
		}
		catch (\Throwable)
		{
			// A broken marker must not hold the dashboard: the attempt is made anyway.
		}

		return true;
	}

	private function isExpirationDateSynced(): bool
	{
		$expected = (int)Option::get('biconnector', self::OPTION_EXPECTED_EXPIRATION, '0');
		$synced = (int)Option::get('biconnector', self::OPTION_SYNCED_EXPIRATION, '-1');

		return $expected === $synced;
	}

	/**
	 * Whether the date Superset has is still ahead. Unknown (-1) is not.
	 */
	private function isSyncedDateInForce(): bool
	{
		return (int)Option::get('biconnector', self::OPTION_SYNCED_EXPIRATION, '-1') > time();
	}

	private function sendExpirationDate(
		?Date $date,
		int $socketTimeout = 0,
		int $streamTimeout = 0,
		bool $detectFrozenInstance = false,
	): Result
	{
		$result = new Result();
		$response = IntegratorFactory::getInstance()->setExpirationDate(
			$date,
			$socketTimeout,
			$streamTimeout,
			$detectFrozenInstance,
		);

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

	/**
	 * Waits for a delivery in progress: it sends the date read before the renewal, and a busy lock would leave
	 * the new date to nobody, as the agent that holds it finishes with the old one.
	 */
	public function actualizeSubscriptionExpirationDate(): void
	{
		$connection = Application::getConnection();
		$isLocked = $connection->lock(self::LOCK_KEY, self::RENEWAL_LOCK_TIMEOUT);

		$this->rememberActualExpirationDate();
		if (!$isLocked)
		{
			// Left to the gate of a market dashboard.
			return;
		}

		try
		{
			$this->deliverPendingExpirationDate();
		}
		finally
		{
			$connection->unlock(self::LOCK_KEY);
		}
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
