<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Dto\FullReport;

trait PayloadListMapTrait
{
	/**
	 * @return list<array<string, int|string|float|bool|null>>|null
	 */
	private static function mapTasks(array $props): ?array
	{
		$tasks = self::mapPayloadList($props, 'tasks', 'ID');
		if ($tasks === null)
		{
			return null;
		}

		foreach ($tasks as $index => $task)
		{
			if (array_key_exists('TIME', $task))
			{
				$time = self::normalizeIntegerValue($task['TIME']);
				if ($time === null)
				{
					return null;
				}

				$tasks[$index]['TIME'] = $time;
			}
		}

		return $tasks;
	}

	/**
	 * @return list<array<string, int|string|float|bool|null>>|null
	 */
	private static function mapEvents(array $props): ?array
	{
		$events = self::mapPayloadList($props, 'events', 'ID');
		if ($events === null)
		{
			return null;
		}

		foreach ($events as $index => $event)
		{
			if (array_key_exists('OWNER_ID', $event))
			{
				$ownerId = self::normalizePositiveIntegerValue($event['OWNER_ID']);
				if ($ownerId === null)
				{
					return null;
				}

				$events[$index]['OWNER_ID'] = $ownerId;
			}
		}

		return $events;
	}

	/**
	 * @return list<array<string, int|string|float|bool|null>>|null
	 */
	private static function mapFiles(array $props): ?array
	{
		return self::mapPayloadList($props, 'files', 'FILE_ID');
	}

	/**
	 * @return list<array<string, int|string|float|bool|null>>|null
	 */
	private static function mapPayloadList(array $props, string $key, string $requiredPositiveIntegerField): ?array
	{
		if (!array_key_exists($key, $props))
		{
			return null;
		}

		$value = $props[$key];
		if (!is_array($value) || !array_is_list($value))
		{
			return null;
		}

		$result = [];
		foreach ($value as $item)
		{
			if (!is_array($item))
			{
				return null;
			}

			$normalizedItem = [];
			foreach ($item as $itemKey => $itemValue)
			{
				if (!is_string($itemKey) || !self::isScalarOrNull($itemValue))
				{
					return null;
				}

				$normalizedItem[$itemKey] = $itemValue;
			}

			$requiredFieldValue = self::normalizePositiveIntegerValue($normalizedItem[$requiredPositiveIntegerField] ?? null);
			if ($requiredFieldValue === null)
			{
				return null;
			}

			$normalizedItem[$requiredPositiveIntegerField] = $requiredFieldValue;
			$result[] = $normalizedItem;
		}

		return $result;
	}

	private static function normalizePositiveIntegerValue(mixed $value): ?int
	{
		$normalizedValue = self::normalizeIntegerValue($value);
		if ($normalizedValue === null || $normalizedValue <= 0)
		{
			return null;
		}

		return $normalizedValue;
	}

	private static function normalizeIntegerValue(mixed $value): ?int
	{
		if (!is_numeric($value))
		{
			return null;
		}

		return (int)$value;
	}

	private static function isScalarOrNull(mixed $value): bool
	{
		return is_scalar($value) || $value === null;
	}
}
