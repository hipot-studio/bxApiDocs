<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\Bizproc;

use Bitrix\Bizproc\FieldType;

/**
 * Value of an entity selector input of an activity, read as the id it stands for.
 *
 * One input carries three shapes. The selector of the settings form stores what was picked as
 * ['id' => .., 'entityId' => ..]; the setup wizard of the digest and the templates written before the
 * selector store a bare number; a template expression resolves into either of those by the time the
 * activity runs. Anything else stands for no value at all: casting a list of ids to int lands on a
 * real id nobody chose.
 *
 * Shared by both digest activities on purpose: they read the same report input, and two copies of the
 * rule would drift apart without anything noticing.
 */
final class EntitySelectorValue
{
	public static function toId(mixed $value): int
	{
		if (is_array($value))
		{
			$value = $value['id'] ?? null;
		}

		return is_scalar($value) ? (int)$value : 0;
	}

	/**
	 * Values of the entity selector inputs of a settings form, kept as the ids they stand for.
	 *
	 * The settings form feeds a stored value back to the selector item by item, so the pair came back as
	 * two picked values: the report and the name of its selector. Storing the id alone is also the shape
	 * the setup wizard writes and the shape the templates written before the selector carry: one input,
	 * one stored shape, whoever filled it.
	 *
	 * @param array<string, mixed> $properties Values as the settings form extracted them.
	 * @param array<string, array> $fieldsMap Properties map of the activity.
	 * @return array<string, mixed>
	 */
	public static function normalizeProperties(array $properties, array $fieldsMap): array
	{
		foreach ($fieldsMap as $property => $field)
		{
			if (
				($field['Type'] ?? '') !== FieldType::ENTITYSELECTOR
				|| !is_array($properties[$property] ?? null)
			)
			{
				continue;
			}

			$id = self::toId($properties[$property]);
			$properties[$property] = $id > 0 ? $id : null;
		}

		return $properties;
	}
}
