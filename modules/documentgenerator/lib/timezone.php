<?php

namespace Bitrix\DocumentGenerator;

use Bitrix\DocumentGenerator\Feature\Timezone as TimezoneFeature;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Type;

final class Timezone
{
	public const OPTION_NAME = 'document_timezone';

	public static function isEnabled(): bool
	{
		return Feature::isEnabled(TimezoneFeature::class);
	}

	public static function getIdentifiers(): array
	{
		static $identifiers;

		$identifiers ??= \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);

		return $identifiers;
	}

	public static function isValid(?string $timezone): bool
	{
		return $timezone !== null && in_array($timezone, self::getIdentifiers(), true);
	}

	public static function resolve(?string $override, ?string $fallback): string
	{
		if (self::isValid($override))
		{
			return $override;
		}

		if (self::isValid($fallback))
		{
			return $fallback;
		}

		return date_default_timezone_get();
	}

	/**
	 * @return array<int, array{value: string, label: string, offset: int}>
	 */
	public static function getItems(?\DateTimeInterface $at = null): array
	{
		$at = $at === null
			? new \DateTimeImmutable()
			: \DateTimeImmutable::createFromInterface($at)
		;
		$items = [];

		foreach (self::getIdentifiers() as $identifier)
		{
			$offset = (new \DateTimeZone($identifier))->getOffset($at);
			$items[] = [
				'value' => $identifier,
				'label' => sprintf('(UTC%s) %s', self::formatOffset($offset), $identifier),
				'offset' => $offset,
			];
		}

		usort(
			$items,
			static fn(array $left, array $right): int =>
				[$left['offset'], $left['value']] <=> [$right['offset'], $right['value']],
		);

		return $items;
	}

	public static function getTimestampForFormatting(Type\DateTime $value, string $timezone): int
	{
		$value = clone $value;
		$value->setDefaultTimeZone();
		$offset = \CTimeZone::calculateOffset($timezone, $value);
		if ($offset !== 0)
		{
			$value->add(($offset < 0 ? '-' : '') . 'PT' . abs($offset) . 'S');
		}

		return $value->getTimestamp();
	}

	private static function formatOffset(int $offset): string
	{
		$absoluteOffset = abs($offset);
		$hours = intdiv($absoluteOffset, 3600);
		$minutes = intdiv($absoluteOffset % 3600, 60);

		return sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', $hours, $minutes);
	}
}
