<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\ProcessData\Service;

use Bitrix\Bizproc\Workflow\Template\SourceType;

/**
 * What an activity writes into (Q-6). A writer names its target by the key of a row of its own property and
 * never by a reference standing in the text, so no reading of the properties as text answers about it and
 * every surface asking "who writes into this element" comes here.
 */
final class ActivityWriteTargets
{
	/**
	 * The property holding what the activity writes, and the source the keys of that property name. A row of
	 * such a property either names its element in `Name` or stands under its key.
	 */
	private const TARGET_OF_ACTIVITY = [
		'SetVariableActivity' => ['property' => 'VariableValue', 'sourceType' => SourceType::Variable],
		'RequestInformationActivity' => ['property' => 'RequestedInformation', 'sourceType' => SourceType::Variable],
		'RequestInformationOptionalActivity' => [
			'property' => 'RequestedInformation',
			'sourceType' => SourceType::Variable,
		],
		'SetGlobalVariableActivity' => ['property' => 'GlobalVariableValue', 'sourceType' => SourceType::GlobalVariable],
		'MathOperationActivity' => ['property' => 'Conditions', 'sourceType' => SourceType::GlobalVariable],
	];

	/**
	 * The elements one activity writes into, in the order its property holds them.
	 *
	 * @return array<int, array{sourceType: string, key: string}>
	 */
	public static function of(array $activity): array
	{
		$target = self::TARGET_OF_ACTIVITY[(string)($activity['Type'] ?? '')] ?? null;
		if ($target === null)
		{
			return [];
		}

		$writerProperty = $activity['Properties'][$target['property']] ?? null;
		if (!is_array($writerProperty))
		{
			return [];
		}

		$targets = [];

		foreach ($writerProperty as $rowKey => $row)
		{
			$name = is_array($row) ? ($row['Name'] ?? null) : null;
			$writtenKey = is_string($name) && !\CBPHelper::isEmptyValue($name) ? $name : $rowKey;

			// A row of a list naming nothing writes nowhere: its index is the key of no element.
			if (!is_string($writtenKey))
			{
				continue;
			}

			$resolved = self::resolveTarget($writtenKey, $target['sourceType']);
			if ($resolved !== null)
			{
				$targets[] = $resolved;
			}
		}

		return $targets;
	}

	/**
	 * The property of an activity writing into the named source, when it writes into it at all. A rename asks
	 * about the source it renames: the same activity writing into another one keeps its keys.
	 */
	public static function propertyOf(string $activityType, string $sourceType): ?string
	{
		$target = self::TARGET_OF_ACTIVITY[$activityType] ?? null;

		return $target !== null && $target['sourceType'] === $sourceType ? $target['property'] : null;
	}

	/**
	 * A key naming its element by an expression - the target of `SetGlobalVariableActivity` - names the source
	 * itself, and a bare key names the source the property writes into by default.
	 *
	 * @return array{sourceType: string, key: string}|null
	 */
	private static function resolveTarget(string $writtenKey, string $defaultSourceType): ?array
	{
		$expression = \CBPActivity::parseExpression($writtenKey);

		if ($expression === null)
		{
			return ['sourceType' => $defaultSourceType, 'key' => $writtenKey];
		}

		$source = SourceType::getObjectSourceType((string)$expression['object'], (string)$expression['field']);

		return $source === null ? null : ['sourceType' => $source[0], 'key' => (string)($source[1] ?? '')];
	}
}
