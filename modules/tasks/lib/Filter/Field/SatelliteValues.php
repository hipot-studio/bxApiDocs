<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Filter\Field;

/**
 * Satellite mode values carried by a single saved filter preset or by the applied filter.
 *
 * Typed contract on the border with main.ui.filter: preset settings arrive from Options::getPresets() nested under
 * fields/additional, applied values from Options::getFilter() as one flat array, and this is the only place in the
 * module that knows either shape.
 */
final class SatelliteValues
{
	private const FIELDS_KEY = 'fields';
	private const ADDITIONAL_KEY = 'additional';

	/**
	 * @param array<string, string> $values
	 */
	private function __construct(private readonly array $values)
	{
	}

	/**
	 * @param list<string> $fieldIds Fields that offer a mode.
	 */
	public static function fromPresetSettings(mixed $settings, array $fieldIds): self
	{
		if (!is_array($settings))
		{
			return new self([]);
		}

		// A field added through "Add field" is stored as additional, and its satellite moves along with it.
		$fields = array_merge(
			is_array($settings[self::FIELDS_KEY] ?? null) ? $settings[self::FIELDS_KEY] : [],
			is_array($settings[self::ADDITIONAL_KEY] ?? null) ? $settings[self::ADDITIONAL_KEY] : [],
		);

		return self::pickSatellites($fields, $fieldIds);
	}

	/**
	 * @param array $filter Applied filter values as Options::getFilter() returns them.
	 * @param list<string> $fieldIds Fields that offer a mode.
	 */
	public static function fromAppliedFilter(array $filter, array $fieldIds): self
	{
		return self::pickSatellites($filter, $fieldIds);
	}

	public function isEmpty(): bool
	{
		return $this->values === [];
	}

	/**
	 * @return array<string, string>
	 */
	public function toArray(): array
	{
		return $this->values;
	}

	/**
	 * @param array $fields Field values keyed by field id, satellites among them.
	 * @param list<string> $fieldIds
	 */
	private static function pickSatellites(array $fields, array $fieldIds): self
	{
		$values = [];
		foreach (self::satelliteKeys($fieldIds) as $satelliteKey)
		{
			$value = $fields[$satelliteKey] ?? null;
			if (is_string($value) && $value !== '')
			{
				$values[$satelliteKey] = $value;
			}
		}

		return new self($values);
	}

	/**
	 * @param list<string> $fieldIds
	 * @return list<string>
	 */
	private static function satelliteKeys(array $fieldIds): array
	{
		$keys = array_map(static fn(string $fieldId): string => Mode::satelliteKey($fieldId), $fieldIds);
		$keys[] = TagUnion::SATELLITE_KEY;
		$keys[] = MemberUnion::SATELLITE_KEY;

		return $keys;
	}
}
