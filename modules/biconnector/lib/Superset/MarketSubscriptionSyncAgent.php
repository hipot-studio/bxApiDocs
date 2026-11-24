<?php

namespace Bitrix\BIConnector\Superset;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Logger\SupersetInitializerLogger;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Retries the delivery of the market subscription expiration date until Superset confirms it.
 *
 * The agent row exists only while the date is pending, so it lives on affected portals only.
 * The name must be built here only: a different spelling (leading slash and so on) creates
 * a duplicate row in b_agent that no longer can be managed.
 *
 * Retries only what a retry may cure. A refusal by the status, the tariff or the service, and an error that outlives
 * MAX_ATTEMPTS, are left to the transition of the instance to READY and to the gate of a market dashboard.
 */
final class MarketSubscriptionSyncAgent
{
	private const MODULE_ID = 'biconnector';
	private const RETRY_DELAY_KEY = 'biconnector.market_subscription.retry_delay';
	private const ATTEMPT_COUNT_KEY = 'biconnector.market_subscription.attempt_count';
	private const FIRST_DELAY = 300;
	private const MAX_DELAY = 3600;

	/**
	 * About a day of retries, most of them at the longest pause.
	 */
	private const MAX_ATTEMPTS = 24;

	/**
	 * Outlives the longest pause between runs. A lost delay only restarts the backoff from FIRST_DELAY,
	 * a lost count only lets the retries go on for another MAX_ATTEMPTS.
	 */
	private const RETRY_STATE_TTL = 2 * self::MAX_DELAY;

	public static function run(): string
	{
		if (!self::canDeliver())
		{
			self::forgetRetryState();

			return '';
		}

		$manager = MarketAccessManager::getInstance();
		if ($manager->syncPendingExpirationDate())
		{
			self::forgetRetryState();
			SupersetInitializerLogger::logInfo('Market subscription expiration date retry finished');

			return '';
		}

		if ($manager->isDeliveryRefused())
		{
			SupersetInitializerLogger::logInfo('Market subscription expiration date retry refused by the service');

			return '';
		}

		$attempts = self::getStoredInt(self::ATTEMPT_COUNT_KEY) + 1;
		if ($attempts >= self::MAX_ATTEMPTS)
		{
			self::forgetRetryState();
			SupersetInitializerLogger::logInfo('Market subscription expiration date retry stopped', [
				'attempts' => $attempts,
			]);

			return '';
		}

		$delay = min(max(self::FIRST_DELAY, self::getStoredInt(self::RETRY_DELAY_KEY) * 2), self::MAX_DELAY);
		self::storeInt(self::ATTEMPT_COUNT_KEY, $attempts);
		self::storeInt(self::RETRY_DELAY_KEY, $delay);
		self::setExecutionPeriod($delay);

		return self::getAgentName();
	}

	/**
	 * Must be called under the delivery lock of MarketAccessManager: CAgent::AddAgent() looks for
	 * an existing row with a plain SELECT, and two parallel calls would create two rows.
	 * Does nothing while the agent row exists, the running agent included: the agent reschedules
	 * itself through $pPERIOD, and AddAgent() would not move NEXT_EXEC of an existing row anyway.
	 */
	public static function schedule(): void
	{
		if (!self::canDeliver() || self::isScheduled())
		{
			return;
		}

		$agentId = \CAgent::AddAgent(
			name: self::getAgentName(),
			module: self::MODULE_ID,
			period: 'N',
			interval: self::MAX_DELAY,
			active: 'Y',
			next_exec: \ConvertTimeStamp(time() + \CTimeZone::GetOffset() + self::FIRST_DELAY, 'FULL'),
			existError: false,
		);

		if ((int)$agentId <= 0)
		{
			return;
		}

		self::forgetRetryState();
		self::storeInt(self::RETRY_DELAY_KEY, self::FIRST_DELAY);
		SupersetInitializerLogger::logInfo('Market subscription expiration date retry scheduled', [
			'delay' => self::FIRST_DELAY,
		]);
	}

	public static function cancel(): void
	{
		\CAgent::RemoveAgent(self::getAgentName(), self::MODULE_ID);
		self::forgetRetryState();
	}

	/**
	 * Anything but READY refuses the credentials, a closed tariff refuses the request before it is sent.
	 */
	private static function canDeliver(): bool
	{
		return SupersetInitializer::isSupersetReady() && Feature::isBuilderEnabled();
	}

	private static function getAgentName(): string
	{
		return self::class . '::run();';
	}

	private static function isScheduled(): bool
	{
		$agent = \CAgent::GetList(
			['ID' => 'ASC'],
			[
				'=NAME' => self::getAgentName(),
				'MODULE_ID' => self::MODULE_ID,
			],
		)->fetch();

		return is_array($agent);
	}

	private static function getStoredInt(string $key): int
	{
		try
		{
			return (int)self::getStorage()->get($key, 0);
		}
		catch (\Throwable)
		{
			// A lost retry state only restarts the backoff; it must not break the delivery or the dashboard.
			return 0;
		}
	}

	private static function storeInt(string $key, int $value): void
	{
		try
		{
			self::getStorage()->set($key, $value, self::RETRY_STATE_TTL);
		}
		catch (\Throwable)
		{
		}
	}

	private static function forgetRetryState(): void
	{
		try
		{
			self::getStorage()->delete(self::RETRY_DELAY_KEY);
			self::getStorage()->delete(self::ATTEMPT_COUNT_KEY);
		}
		catch (\Throwable)
		{
		}
	}

	private static function getStorage(): PersistentStorageInterface
	{
		return ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	/**
	 * @see \CAgent::ExecuteAgents()
	 */
	private static function setExecutionPeriod(int $delaySeconds): void
	{
		global $pPERIOD;

		$pPERIOD = $delaySeconds;
	}
}
