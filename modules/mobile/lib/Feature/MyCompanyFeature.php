<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Config\FeatureFlag;

final class MyCompanyFeature extends FeatureFlag
{
	private const OPTION_CODE = 'feature_my_company_enabled';
	public const LAUNCH_CUTOFF_OPTION_CODE = 'my_company_launch_cutoff_date';
	public const LAUNCH_CUTOFF_DATE_FORMAT = 'Y-m-d';

	public function isEnabled(): bool
	{
		return $this->isOptionOn('mobile', self::OPTION_CODE, false);
	}

	public function enable(): void
	{
		if ($this->getLaunchCutoffDateValue() === '')
		{
			$this->setLaunchCutoffDate((new \DateTimeImmutable())->format(self::LAUNCH_CUTOFF_DATE_FORMAT));
		}

		Option::set('mobile', self::OPTION_CODE, true);
	}

	public function disable(): void
	{
		Option::delete('mobile', ['name' => self::OPTION_CODE]);
	}

	public function getLaunchCutoffDateValue(): string
	{
		return (string)Option::getRealValue('mobile', self::LAUNCH_CUTOFF_OPTION_CODE, '');
	}

	public function setLaunchCutoffDate(string $value): bool
	{
		if ($this->parseLaunchCutoffDate($value) === null)
		{
			return false;
		}

		Option::set('mobile', self::LAUNCH_CUTOFF_OPTION_CODE, $value);

		return true;
	}

	public function resetLaunchCutoffDate(): void
	{
		Option::delete('mobile', ['name' => self::LAUNCH_CUTOFF_OPTION_CODE]);
	}

	/**
	 * Returns whether the portal belongs to the post-launch cohort. Null means that the cutoff cannot
	 * be applied. The comparison is calendar-day based because the stored cutoff has no time component.
	 */
	public function isPortalCreatedAfterLaunch(DateTime $portalCreatedAt): ?bool
	{
		$cutoff = $this->parseLaunchCutoffDate($this->getLaunchCutoffDateValue());
		if ($cutoff === null)
		{
			return null;
		}

		$portalDate = $this->parseLaunchCutoffDate(
			$portalCreatedAt->format(self::LAUNCH_CUTOFF_DATE_FORMAT),
		);

		return $portalDate === null ? null : $portalDate > $cutoff;
	}

	private function parseLaunchCutoffDate(string $value): ?\DateTimeImmutable
	{
		$date = \DateTimeImmutable::createFromFormat('!' . self::LAUNCH_CUTOFF_DATE_FORMAT, $value);

		return $date !== false && $date->format(self::LAUNCH_CUTOFF_DATE_FORMAT) === $value
			? $date
			: null;
	}
}
