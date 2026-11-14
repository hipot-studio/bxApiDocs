<?php

namespace Bitrix\Mobile\Internal\Onboarding\Conditions;

use Bitrix\Main\Result;
use Bitrix\Mobile\Internal\Onboarding\Checkers\PortalAgeChecker;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;
use Bitrix\Mobile\Internal\Onboarding\Schedule\SeriesCalendar;

readonly class PushSendConditionChain
{
	/**
	 * @param array<PushSendCondition> $conditions
	 */
	public function __construct(private array $conditions)
	{}

	/**
	 * $nowTimestamp is the moment the window is judged at. Null means the clock on the wall,
	 * which is what the hourly agent runs by; a run that states its moment - the dev harness
	 * states one for the whole pipeline - has to state it here too, or the window would answer
	 * for a different moment than the schedule of the same run.
	 */
	public static function createChain(
		?PortalAgeChecker $portalAgeChecker = null,
		?int $nowTimestamp = null,
		?SeriesCalendar $calendar = null,
	): self
	{
		$conditions = self::baseConditions($portalAgeChecker);
		$conditions[] = new PushTimeCondition($nowTimestamp, $calendar);

		return new self($conditions);
	}

	/**
	 * The chain of a run that states its own moment: everything the ordinary chain checks
	 * except the send window, which such a run is deliberately outside of.
	 */
	public static function createForceChain(?PortalAgeChecker $portalAgeChecker = null): self
	{
		return new self(self::baseConditions($portalAgeChecker));
	}

	/**
	 * What every run is judged by, whatever it may skip. A condition added to one chain and
	 * forgotten in the other is a forced run quietly weaker than the hourly one.
	 *
	 * @return array<PushSendCondition>
	 */
	private static function baseConditions(?PortalAgeChecker $portalAgeChecker): array
	{
		$conditions = [
			new RegionSupportedCondition(),
		];

		if ($portalAgeChecker !== null)
		{
			$conditions[] = new PortalAgeCondition($portalAgeChecker);
		}

		return $conditions;
	}

	public function check(RegionConfig $config): Result
	{
		foreach ($this->conditions as $condition)
		{
			$result = $condition->check($config);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		return new Result();
	}
}
