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
	public const DEFAULT_LAUNCH_CUTOFF_DATE = '2026-10-19';

	public function isEnabled(): bool
	{
		return Option::get('mobile', self::OPTION_CODE, 'Y') === 'Y';
	}

	public function enable(): void
	{
		Option::set('mobile', self::OPTION_CODE, 'Y');
	}

	public function disable(): void
	{
		Option::set('mobile', self::OPTION_CODE, 'N');
	}

	public function getLaunchCutoffDateValue(): string
	{
		return (string)(
			Option::getRealValue('mobile', self::LAUNCH_CUTOFF_OPTION_CODE, '')
			?? self::DEFAULT_LAUNCH_CUTOFF_DATE
		);
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
