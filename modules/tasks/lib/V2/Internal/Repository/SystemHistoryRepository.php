<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Tasks\Internals\SystemLogTable;
use Bitrix\Tasks\V2\Internal\Entity\SystemHistoryLogCollection;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\SystemHistoryLogMapper;
use Bitrix\Tasks\V2\Internal\Repository\SystemHistoryRepositoryInterface;

class SystemHistoryRepository implements SystemHistoryRepositoryInterface
{
	public function __construct(
		private readonly SystemHistoryLogMapper $templateHistoryLogMapper,
	)
	{

	}

	public function tail(int $templateId, int $offset = 0, int $limit = 50): SystemHistoryLogCollection
	{
		$templateHistoryLogList =
			SystemLogTable::query()
				->setSelect([
					'ID',
					'TYPE',
					'CREATED_DATE',
					'MESSAGE',
					'ERROR',
				])
				->setOrder(['ID' => 'DESC'])
				->setDistinct(false)
				->setOffset($offset)
				->setLimit($limit)
				->where('ENTITY_TYPE', SystemLogTable::ENTITY_TYPE_TEMPLATE)
				->where('ENTITY_ID', $templateId)
				->exec()
				->fetchAll()
		;

		return $this->templateHistoryLogMapper->mapToCollection($templateHistoryLogList);
	}

	public function count(int $templateId): int
	{
		$queryResult =
			SystemLogTable::query()
				->setSelect([new ExpressionField('CNT', 'COUNT(1)')])
				->where('ENTITY_TYPE', SystemLogTable::ENTITY_TYPE_TEMPLATE)
				->where('ENTITY_ID', $templateId)
				->setDistinct(false)
				->setLimit(1)
				->exec()
				->fetch()
		;

		if (!is_array($queryResult))
		{
			return 0;
		}

		return (int)($queryResult['CNT'] ?? 0);
	}
}
