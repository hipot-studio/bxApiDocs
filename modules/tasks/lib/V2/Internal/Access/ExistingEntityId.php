<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access;

use Bitrix\Main\Error;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\EntityCollectionInterface;
use Bitrix\Tasks\V2\Internal\Entity\EntityInterface;

final class ExistingEntityId
{
	public static function normalize(mixed $id): ?int
	{
		if (is_int($id))
		{
			return $id > 0 ? $id : null;
		}

		if (!is_string($id) || $id === '' || preg_match('/^[0-9]+$/D', $id) !== 1)
		{
			return null;
		}

		$id = ltrim($id, '0');
		if ($id === '')
		{
			return null;
		}

		$maxId = (string)PHP_INT_MAX;
		if (
			strlen($id) > strlen($maxId)
			|| (strlen($id) === strlen($maxId) && strcmp($id, $maxId) > 0)
		)
		{
			return null;
		}

		return (int)$id;
	}

	public static function isPositive(mixed $id): bool
	{
		return self::normalize($id) !== null;
	}

	public static function errorFor(EntityInterface|EntityCollectionInterface $entity): Error
	{
		$sample = $entity instanceof EntityCollectionInterface
			? self::firstItem($entity)
			: $entity;

		return match (true)
		{
			$sample instanceof Entity\Task,
			$sample instanceof Entity\Task\GanttLink => new Error(
				'Task ID must be a positive integer',
				'INVALID_TASK_ID',
			),
			$sample instanceof Entity\Template => new Error(
				'Template ID must be a positive integer',
				'INVALID_TEMPLATE_ID',
			),
			$sample instanceof Entity\Group => new Error(
				'Group ID must be a positive integer',
				'INVALID_GROUP_ID',
			),
			$sample instanceof Entity\Flow => new Error(
				'Flow ID must be a positive integer',
				'INVALID_FLOW_ID',
			),
			$sample instanceof Entity\Result => new Error(
				'Result ID must be a positive integer',
				'INVALID_RESULT_ID',
			),
			$sample instanceof Entity\Task\Reminder => new Error(
				'Reminder ID must be a positive integer',
				'INVALID_REMINDER_ID',
			),
			$sample instanceof Entity\Task\ElapsedTime => new Error(
				'Elapsed time ID must be a positive integer',
				'INVALID_ELAPSED_TIME_ID',
			),
			default => new Error(
				'ID must be a positive integer',
				'INVALID_ENTITY_ID',
			),
		};
	}

	private static function firstItem(EntityCollectionInterface $collection): ?EntityInterface
	{
		foreach ($collection as $item)
		{
			if ($item instanceof EntityInterface)
			{
				return $item;
			}
		}

		return null;
	}
}
