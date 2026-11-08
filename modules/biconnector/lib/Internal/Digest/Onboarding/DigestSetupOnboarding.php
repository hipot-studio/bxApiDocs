<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Onboarding;

use Bitrix\BIConnector\Internal\Digest\Provider\DigestNamedLock;
use Bitrix\BIConnector\Internal\Digest\Service\DigestSetupAvailability;
use Bitrix\BIConnector\Internal\Digest\Service\DigestSetupAvailabilityService;
use Bitrix\Main\Application;

final class DigestSetupOnboarding
{
	public const CURRENT_VERSION = 'v1';

	private const OPTION_NAME_PREFIX = 'digestSetupOnboarding.';
	private const LOCK_PREFIX = 'biconnector.digest-setup-onboarding.';

	private readonly DigestNamedLock $lock;
	private readonly DigestSetupOnboardingMarkStorage $markStorage;

	public function __construct(
		private readonly DigestSetupAvailabilityService $availabilityService,
		?DigestNamedLock $lock = null,
		?DigestSetupOnboardingMarkStorage $markStorage = null,
	)
	{
		$this->lock = $lock ?? new DigestNamedLock(Application::getConnection());
		$this->markStorage = $markStorage
			?? new DigestSetupOnboardingMarkStorage(Application::getInstance()->getConnectionPool());
	}

	/**
	 * Cheap cached read for page rendering; claim() stays the authority against races.
	 */
	public function isShown(string $version, int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return $this->markStorage->isSpent(self::OPTION_NAME_PREFIX . $version, $userId);
	}

	public function claim(string $version, int $userId): bool
	{
		if ($version !== self::CURRENT_VERSION || $userId <= 0)
		{
			return false;
		}

		$lockName = self::LOCK_PREFIX . $userId;
		if (!$this->lock->acquire($lockName))
		{
			return false;
		}

		try
		{
			if (!$this->isAvailable($userId))
			{
				return false;
			}

			$optionName = self::OPTION_NAME_PREFIX . $version;
			if ($this->markStorage->isSpentOnMaster($optionName, $userId))
			{
				return false;
			}

			return $this->markStorage->markSpent($optionName, $userId);
		}
		catch (\Throwable)
		{
			return false;
		}
		finally
		{
			$this->lock->release($lockName);
		}
	}

	private function isAvailable(int $userId): bool
	{
		return in_array(
			$this->availabilityService->check(null, $userId)->state,
			[DigestSetupAvailability::READY, DigestSetupAvailability::SUBSCRIPTION_REQUIRED],
			true,
		);
	}
}
