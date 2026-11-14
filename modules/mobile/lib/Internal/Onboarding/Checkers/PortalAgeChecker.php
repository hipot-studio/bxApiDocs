<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Onboarding\Schedule\SeriesCalendar;

class PortalAgeChecker
{
	private ?DateTime $portalCreatedAt = null;

	public function isPortalDateKnown(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return $this->getPortalCreatedAt() !== null;
	}

	public function isEligible(int $maxDays, ?DateTime $now = null, ?SeriesCalendar $calendar = null): bool
	{
		if (!$this->isPortalDateKnown())
		{
			return false;
		}

		$age = $this->getPortalAgeDays($now, $calendar);

		return $age <= $maxDays;
	}

	public function getPortalAgeDays(?DateTime $now = null, ?SeriesCalendar $calendar = null): int
	{
		$createdAt = $this->getPortalCreatedAt();
		if ($createdAt === null)
		{
			return 0;
		}

		$now ??= new DateTime();
		$createdDay = ($calendar?->toCalendarTime($createdAt) ?? clone $createdAt)->setTime(0, 0, 0);
		$today = ($calendar?->toCalendarTime($now) ?? clone $now)->setTime(0, 0, 0);

		return (int)round(($today->getTimestamp() - $createdDay->getTimestamp()) / 86400);
	}

	public function getPortalCreatedAt(): ?DateTime
	{
		if ($this->portalCreatedAt !== null)
		{
			return $this->portalCreatedAt;
		}

		$this->portalCreatedAt = $this->detectPortalCreatedAt();

		return $this->portalCreatedAt;
	}

	protected function detectPortalCreatedAt(): ?DateTime
	{
		$timestamp = \CBitrix24::getCreateTime();
		if (empty($timestamp))
		{
			return null;
		}

		return DateTime::createFromTimestamp((int)$timestamp);
	}
}
