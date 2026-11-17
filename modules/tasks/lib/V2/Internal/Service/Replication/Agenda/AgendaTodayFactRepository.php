<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Agenda;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Provider;
use Bitrix\Tasks\V2\Internal\Repository\Replication\InstanceFactRepository;

class AgendaTodayFactRepository
{
	/**
	 * @param int[] $templateIds
	 * @param array{ts:int,id:int}|null $cursor
	 * @return array<int, array{taskId:int,templateId:int,name:string,when:int,completed:bool,responsibleId:int}>
	 */
	public function getInstances(
		int $viewerId,
		array $templateIds,
		int $from,
		int $to,
		int $limit,
		?array $cursor = null,
		?bool $completedFilter = null,
	): array
	{
		Collection::normalizeArrayValuesByInt($templateIds, false);
		if ($templateIds === [] || $limit < 1 || $to < $from)
		{
			return [];
		}

		$where = [
			'@FORKED_BY_TEMPLATE_ID' => $templateIds,
			'>=CREATED_DATE' => DateTime::createFromTimestamp($from),
			'<=CREATED_DATE' => DateTime::createFromTimestamp($to),
		];
		if ($completedFilter !== null)
		{
			$where[($completedFilter ? '=' : '!=') . 'REAL_STATUS'] = Status::COMPLETED;
		}

		$taskQuery = (new Provider\Query\TaskQuery($viewerId))
			->setSelect([
				'ID',
				'TITLE',
				'FORKED_BY_TEMPLATE_ID',
				'CREATED_DATE',
				'REAL_STATUS',
				'RESPONSIBLE_ID',
			])
			->setWhere($where)
			->setOrder([
				'CREATED_DATE' => Provider\Query\TaskQueryInterface::SORT_ASC,
				'ID' => Provider\Query\TaskQueryInterface::SORT_ASC,
			])
			->setLimit($limit)
		;
		$query = Provider\Query\TaskQueryBuilder::build($taskQuery);

		if ($cursor !== null)
		{
			$cursorDate = DateTime::createFromTimestamp($cursor['ts']);
			$query->where(
				Query::filter()
					->logic(ConditionTree::LOGIC_OR)
					->where('CREATED_DATE', '>', $cursorDate)
					->where(
						Query::filter()
							->where('CREATED_DATE', $cursorDate)
							->where('ID', '>', $cursor['id'])
					)
			);
		}

		$result = [];
		foreach ($query->exec()->fetchAll() as $row)
		{
			$createdDate = $row['CREATED_DATE'] ?? null;
			if (!$createdDate instanceof DateTime)
			{
				continue;
			}

			$result[] = [
				'taskId' => (int)$row['ID'],
				'templateId' => (int)$row['FORKED_BY_TEMPLATE_ID'],
				'name' => (string)($row['TITLE'] ?? ''),
				'when' => $createdDate->getTimestamp(),
				'completed' => InstanceFactRepository::isCompletedStatus($row['REAL_STATUS'] ?? null),
				'responsibleId' => (int)($row['RESPONSIBLE_ID'] ?? 0),
			];
		}

		return $result;
	}
}
