<?php

namespace Bitrix\Crm\RepeatSale\DataCollector\Activity\Strategy;

use Bitrix\Crm\Activity\Provider\ToDo\ToDo;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\ActivityType;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\PreselectedActivitiesInterface;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\StrategyBase;

class TodosStrategy extends StrategyBase implements PreselectedActivitiesInterface
{
	public function getType(): ActivityType
	{
		return ActivityType::TODOS;
	}

	public function collect(int $entityId, int $limit): array
	{
		$query = $this
			->queryBuilder
			->buildActivityQuery($this->entityTypeId, $entityId, ToDo::PROVIDER_ID, $limit)
		;

		$todosRaw = $query
			->setSelect(['SUBJECT', 'DESCRIPTION'])
			->whereNotNull('DESCRIPTION')
			->fetchAll()
		;

		return $this->normalizeTodos($todosRaw);
	}

	/**
	 * The text of a to-do item is a `longtext` the caller that named the activities did not read, so it
	 * is read here - through the primary key of those few activities and not through the owner of them.
	 */
	public function collectByActivityIds(array $activityIds): array
	{
		if (empty($activityIds))
		{
			return [];
		}

		$rows = $this
			->queryBuilder
			->buildActivityByIdsQuery($activityIds)
			->setSelect(['ID', 'SUBJECT', 'DESCRIPTION'])
			->whereNotNull('DESCRIPTION')
			->fetchAll()
		;

		return $this->normalizeTodos(self::inTheOrderOf($activityIds, $rows));
	}

	private function normalizeTodos(array $todosRaw): array
	{
		if (empty($todosRaw))
		{
			return [];
		}

		$todos = [];
		foreach ($todosRaw as $row)
		{
			$subject = $row['SUBJECT'] ?? '';
			$description = $row['DESCRIPTION'] ?? '';
			if (empty($description))
			{
				continue;
			}

			$todoText = $this->formatTodoText($subject, $description);
			$normalizedText = $this
				->textNormalizer
				->normalize($todoText, $this->getType())
			;
			if ($normalizedText !== null)
			{
				$todos[] = $normalizedText;
			}
		}

		return $this->filterValidData($todos);
	}

	/**
	 * A lookup by the primary key answers in the order of the storage, while the caller named the
	 * activities in the order it wants the records in.
	 *
	 * @param int[] $activityIds
	 */
	private static function inTheOrderOf(array $activityIds, array $rows): array
	{
		$byActivity = array_column($rows, null, 'ID');

		$ordered = [];
		foreach ($activityIds as $activityId)
		{
			if (isset($byActivity[$activityId]))
			{
				$ordered[] = $byActivity[$activityId];
			}
		}

		return $ordered;
	}

	private function formatTodoText(string $subject, string $description): string
	{
		if (empty($subject))
		{
			return $description;
		}

		return sprintf('%s: %s', $subject, $description);
	}
}
