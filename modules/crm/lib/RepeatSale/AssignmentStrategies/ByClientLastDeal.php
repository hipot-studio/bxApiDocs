<?php

namespace Bitrix\Crm\RepeatSale\AssignmentStrategies;

use Bitrix\Crm\Binding\DealContactTable;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\Item;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\Expressions\ColumnExpression;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use CCrmOwnerType;

final class ByClientLastDeal extends Base
{
	private const AVAILABLE_ENTITY_TYPES = [
		CCrmOwnerType::Contact,
		CCrmOwnerType::Company,
	];

	private ?array $clientAssignmentIds = [];

	public function getAssignmentUserId(Item $item, ?int $lastAssignmentUserId): ?int
	{
		$ids = $this->getAssignmentIds();

		return $ids[$item->getId()] ?? $lastAssignmentUserId;
	}

	private function getAssignmentIds(): array
	{
		if (!isset($this->clientAssignmentIds[$this->entityTypeId]))
		{
			$clientEntityIds = array_map(static fn($item) => $item->getId(), $this->items);

			if (!$this->check($clientEntityIds))
			{
				$this->clientAssignmentIds[$this->entityTypeId] = [];

				return [];
			}

			if ($this->entityTypeId === CCrmOwnerType::Contact)
			{
				$fieldName = Item::FIELD_NAME_CONTACT_ID;
			}
			else
			{
				$fieldName = Item::FIELD_NAME_COMPANY_ID;
			}

			$query = new Query(DealTable::getEntity());
			$referenceField = new Reference(
				'D2',
				DealTable::class,
				Join::on('this.' . $fieldName, 'ref.' . $fieldName)
					->where(
						Query::filter()
							->logic('OR')
							->where('ref.DATE_CREATE', '<', new ColumnExpression('this.DATE_CREATE'))
							->where(
								Query::filter()
									->where('ref.DATE_CREATE', new ColumnExpression('this.DATE_CREATE'))
									->where('ref.ID', '<', new ColumnExpression('this.ID')),
							),
					),
				[
					'join_type' => 'LEFT',
				],
			);
			$query->registerRuntimeField('D2', $referenceField);
			$query->setSelect([$fieldName, 'ASSIGNED_BY_ID']);

			if ($this->entityTypeId === CCrmOwnerType::Contact)
			{
				$subQuery = DealContactTable::query()
					->addSelect('DEAL_ID')
					->whereIn(Item::FIELD_NAME_CONTACT_ID, $clientEntityIds)
				;
				$query->setFilter(['@D2.ID' => new SqlExpression($subQuery->getQuery())]);
			}
			else
			{
				$query->setFilter(['=D2.COMPANY_ID' => $clientEntityIds]);
			}

			$lastDealsQueryResult = $query->exec()->fetchAll();
			$clientWithLastDeals = [];
			foreach ($lastDealsQueryResult as $item)
			{
				$assignmentUserId = isset($item['ASSIGNED_BY_ID']) ? (int)$item['ASSIGNED_BY_ID'] : null;
				$clientWithLastDeals[$item[$fieldName]] = $assignmentUserId;
			}

			$this->clientAssignmentIds[$this->entityTypeId] = $clientWithLastDeals;
		}

		return $this->clientAssignmentIds[$this->entityTypeId];
	}

	private function check(array $clientEntityIds): bool
	{
		if (empty($clientEntityIds))
		{
			return false;
		}

		return in_array($this->entityTypeId, self::AVAILABLE_ENTITY_TYPES, true);
	}
}
