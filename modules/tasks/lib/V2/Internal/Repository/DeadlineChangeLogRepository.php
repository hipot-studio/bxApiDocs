<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Model\DeadlineChangeLogTable;
use Bitrix\Main\DB\SqlQueryException;

class DeadlineChangeLogRepository implements DeadlineChangeLogRepositoryInterface
{
	/**
	 * @inheritDoc
	 */
	public function append(
		int $taskId,
		int $userId,
		?DateTime $dateTime,
		?string $reason
	): void
	{
		$result = DeadlineChangeLogTable::add([
			'TASK_ID' => $taskId,
			'USER_ID' => $userId,
			'NEW_DEADLINE' => $dateTime,
			'REASON' => $reason,
			'CHANGED_AT' => new DateTime(),
		]);

		if (!$result->isSuccess())
		{
			throw new SqlQueryException(implode('; ', $result->getErrorMessages()));
		}
	}

	public function clean(int $taskId): bool
	{
		$changes = DeadlineChangeLogTable::getList([
			'select' => ['ID'],
			'filter' => ['TASK_ID' => $taskId],
		])->fetchAll();

		foreach ($changes as $change)
		{
			DeadlineChangeLogTable::delete($change['ID']);
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function countUserChanges(int $userId, int $taskId): int
	{
		$result = DeadlineChangeLogTable::query()
			->where('USER_ID', $userId)
			->where('TASK_ID', $taskId)
			->setSelect(['CNT'])
			->registerRuntimeField(
				new \Bitrix\Main\ORM\Fields\ExpressionField('CNT', 'COUNT(*)')
			)
			->fetch()
		;

		return isset($result['CNT']) ? (int)$result['CNT'] : 0;
	}

	/**
	 * @inheritDoc
	 */
	public function countUserChangesBatch(int $userId, array $taskIds): array
	{
		if (empty($taskIds))
		{
			return [];
		}

		$rows = DeadlineChangeLogTable::query()
			->where('USER_ID', $userId)
			->whereIn('TASK_ID', $taskIds)
			->setSelect(['TASK_ID', 'CNT'])
			->setGroup(['TASK_ID'])
			->registerRuntimeField(
				new \Bitrix\Main\ORM\Fields\ExpressionField('CNT', 'COUNT(*)')
			)
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['TASK_ID']] = (int)$row['CNT'];
		}

		return $result;
	}
}
