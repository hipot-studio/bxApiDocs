<?php

namespace Bitrix\BIConnector\Internal\Services\Scope;

use Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable;
use Bitrix\Bizproc\Workflow\Entity\WorkflowUserTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

class WorkflowTemplateAccessibilityService
{
	/**
	 * @param array<string, string> $orderBy ORM order applied in SQL.
	 *
	 * @return array<array{id:int, name:string}>
	 */
	public function findAccessibleForUser(
		int $userId,
		?string $search,
		int $limit,
		int $offset,
		array $orderBy = ['TEMPLATE.NAME' => 'ASC'],
	): array
	{
		if ($userId <= 0 || !Loader::includeModule('bizproc'))
		{
			return [];
		}

		$query = $this->buildBaseQuery($userId)
			->setSelect([
				'TID' => 'WORKFLOW_TEMPLATE_ID',
				'TNAME' => 'TEMPLATE.NAME',
			])
			->setGroup(['WORKFLOW_TEMPLATE_ID', 'TEMPLATE.NAME'])
			->setOrder($orderBy)
		;

		if ($limit > 0)
		{
			$query->setLimit($limit)->setOffset($offset);
		}

		if ($search !== null && $search !== '')
		{
			$query->whereLike('TEMPLATE.NAME', '%' . $search . '%');
		}

		$result = [];
		$rows = $query->exec();
		while ($row = $rows->fetch())
		{
			$result[] = [
				'id' => (int)$row['TID'],
				'name' => (string)$row['TNAME'],
			];
		}

		return $result;
	}

	public function isAccessibleForUser(int $userId, int $templateId): bool
	{
		if ($userId <= 0 || $templateId <= 0 || !Loader::includeModule('bizproc'))
		{
			return false;
		}

		$row = $this->buildBaseQuery($userId)
			->setSelect(['WORKFLOW_TEMPLATE_ID'])
			->where('WORKFLOW_TEMPLATE_ID', $templateId)
			->setLimit(1)
			->exec()
			->fetch()
		;

		return $row !== false;
	}

	private function buildBaseQuery(int $userId): Query
	{
		$subQuery = WorkflowUserTable::query()
			->addSelect('WORKFLOW_ID')
			->addFilter('=USER_ID', $userId)
		;

		$query = WorkflowStateTable::query();
		$query->registerRuntimeField(
			'',
			new Reference(
				'M',
				Entity::getInstanceByQuery($subQuery),
				Join::on('this.ID', 'ref.WORKFLOW_ID'),
				['join_type' => Join::TYPE_INNER]
			)
		);

		return $query;
	}
}
