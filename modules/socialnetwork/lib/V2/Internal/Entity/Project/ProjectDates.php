<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Project;

use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\V2\Internal\Entity\ValueObjectInterface;

class ProjectDates implements ValueObjectInterface
{
	public function __construct(
		public readonly ?DateTime $start = null,
		public readonly ?DateTime $finish = null,
	)
	{
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			start: self::mapDateTime($props['startTs'] ?? null),
			finish: self::mapDateTime($props['finishTs'] ?? null),
		);
	}

	public function toArray(): array
	{
		return [
			'startTs' => $this->start?->getTimestamp(),
			'finishTs' => $this->finish?->getTimestamp(),
		];
	}

	private static function mapDateTime(mixed $value): ?DateTime
	{
		if ($value instanceof DateTime)
		{
			return $value;
		}

		if (!is_int($value) && !is_numeric($value))
		{
			return null;
		}

		return DateTime::createFromTimestamp((int)$value);
	}
}
