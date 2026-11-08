<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;

class MandatoryPeriodProvider
{
	private const ALLOWED_DAYS = [14, 30, 60];
	private const DEFAULT_DAYS = 14;

	public function __construct(
		private readonly OtpSettings $otpSettings,
	) {
	}

	/**
	 * @return list<array{value: int, name: string, selected: bool}>
	 */
	public function getList(): array
	{
		$current = $this->otpSettings->getSkipMandatoryDays();
		$values = self::ALLOWED_DAYS;

		if ($current > 0 && !in_array($current, $values, true))
		{
			$values[] = $current;
			sort($values);
		}

		$selectedValue = $this->getCurrentPeriod();
		$items = [];

		foreach ($values as $value)
		{
			$items[] = [
				'value' => $value,
				'name' => FormatDate('ddiff', time() - 60 * 60 * 24 * $value),
				'selected' => $value === $selectedValue,
			];
		}

		return $items;
	}

	public function getCurrentPeriod(): int
	{
		$current = $this->otpSettings->getSkipMandatoryDays();

		return $current > 0 ? $current : self::DEFAULT_DAYS;
	}

	public function isAllowedPeriod(int $days): bool
	{
		if (in_array($days, self::ALLOWED_DAYS, true))
		{
			return true;
		}

		return $days > 0 && $days === $this->otpSettings->getSkipMandatoryDays();
	}

	/**
	 * Checks a value as it came from the request, before any type coercion loses its original form
	 */
	public function isAllowedRawPeriod(mixed $days): bool
	{
		if (!is_int($days) && !is_string($days))
		{
			return false;
		}

		if ((string)(int)$days !== (string)$days)
		{
			return false;
		}

		return $this->isAllowedPeriod((int)$days);
	}
}
