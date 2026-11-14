<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Integration\Timeman;

use Bitrix\Main\SystemException;
use Bitrix\Timeman\Helper\TimeHelper;

/**
 * The zone of an employee as work time tracking keeps his day in. The helper of timeman comes off the factory the
 * composition root hands over (stafftrack/.settings.php) and is asked for at every reading: building this service,
 * or a provider holding it, includes no timeman, and the request-wide caches of the helper are the ones every other
 * reader of it shares.
 */
class TimeZoneService
{
	/** @var (\Closure(): ?TimeHelper)|null */
	private readonly ?\Closure $timeHelperFactory;

	/**
	 * @param (\Closure(): ?TimeHelper)|null $timeHelperFactory the helper of timeman, null where timeman is not
	 *     installed; without a factory the service answers as a portal without timeman
	 */
	public function __construct(?\Closure $timeHelperFactory = null)
	{
		$this->timeHelperFactory = $timeHelperFactory;
	}

	/**
	 * The offset of the employee from the server zone at that moment, in the zone work time tracking records his
	 * day in.
	 *
	 * @return int|null null without timeman
	 */
	public function getEmployeeOffsetFromServer(int $userId, int $timestamp): ?int
	{
		$timeHelper = $this->getTimeHelper();
		if ($timeHelper === null)
		{
			return null;
		}

		// getOffsetAt() is counted from UTC, the day window and dateCreate expect it from the server zone
		return $timeHelper->getOffsetAt($userId, $timestamp) - $timeHelper->getServerOffsetAt($timestamp);
	}

	/**
	 * The zone of the employee resolved from the one his profile stores, which the caller has already read: the
	 * per-user method of the helper reads b_user one user at a time.
	 *
	 * @throws SystemException without timeman
	 */
	public function resolveEmployeeZone(int $userId, string $persistedTimeZoneId): string
	{
		$timeHelper = $this->getTimeHelper();
		if ($timeHelper === null)
		{
			throw new SystemException('timeman is not available');
		}

		return $timeHelper->resolveEffectiveTimeZoneIdFromPersisted($userId, $persistedTimeZoneId);
	}

	private function getTimeHelper(): ?TimeHelper
	{
		return $this->timeHelperFactory === null ? null : ($this->timeHelperFactory)();
	}
}
