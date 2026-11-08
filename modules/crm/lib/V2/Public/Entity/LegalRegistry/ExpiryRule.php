<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use InvalidArgumentException;

final readonly class ExpiryRule
{
	/**
	 * Creates a typed expiry rule independently of catalog validity.
	 *
	 * @param string $kind
	 * @param ?string $unit
	 * @param ?int $amount
	 * @param ?string $date
	 * @throws InvalidArgumentException
	 */
	public function __construct(
		public string $kind,
		public ?string $unit = null,
		public ?int $amount = null,
		public ?string $date = null,
	)
	{
		$valid = match ($kind)
		{
			'none' => $unit === null && $amount === null && $date === null,
			'period' => in_array($unit, ['days', 'months', 'years'], true)
				&& $amount !== null && $amount > 0 && $date === null,
			'date' => $unit === null && $amount === null && self::isCalendarDate($date),
			default => false,
		};
		if (!$valid)
		{
			throw new InvalidArgumentException('Invalid expiry rule.');
		}
	}

	private static function isCalendarDate(?string $date): bool
	{
		if ($date === null || preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $matches) !== 1)
		{
			return false;
		}

		return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]);
	}
}
