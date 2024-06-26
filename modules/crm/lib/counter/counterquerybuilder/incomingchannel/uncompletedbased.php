<?php

namespace Bitrix\Crm\Counter\CounterQueryBuilder\IncomingChannel;

use Bitrix\Crm\Activity\Entity\EntityUncompletedActivityTable;
use Bitrix\Crm\Counter\CounterQueryBuilder\BuilderParams\QueryParams;
use Bitrix\Crm\Counter\CounterQueryBuilder\CounterQueryBuilder;
use Bitrix\Crm\Counter\CounterQueryBuilder\QueryParts;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

final class UncompletedBased implements CounterQueryBuilder
{

	private QueryParts\ResponsibleFilter $responsibleFilter;

	public function __construct()
	{
		$this->responsibleFilter = new QueryParts\ResponsibleFilter();
	}

	public function build(Factory $factory, QueryParams $params): Query
	{
		$query = $factory->getDataClass()::query();

		(new QueryParts\SelectFields)->applyForUncompleted($query, $params);

		$referenceFilter = (new ConditionTree())
			->whereColumn('ref.ENTITY_ID', 'this.ID')
			->where('ref.ENTITY_TYPE_ID', new SqlExpression($params->entityTypeId()));

		$referenceFilter->where('ref.IS_INCOMING_CHANNEL', new SqlExpression('?', 'Y'));

		if ($params->useActivityResponsible())
		{
			$this->responsibleFilter->apply($referenceFilter, $params->userParams(), 'ref.RESPONSIBLE_ID');
		}
		else
		{
			$referenceFilter->where('ref.RESPONSIBLE_ID', new SqlExpression('?i', 0));
		}

		$query->registerRuntimeField(
			'',
			new ReferenceField('B',
				EntityUncompletedActivityTable::getEntity(),
				$referenceFilter,
				['join_type' => Join::TYPE_INNER]
			)
		);

		$this->applyResponsibleFilter($factory, $query, $params);

		(new QueryParts\EntitySpecificFilter())->apply($query, $params->entityTypeId(), $params->options());

		return $query;
	}

	private function applyResponsibleFilter(Factory $factory, Query $query, QueryParams $params): void
	{
		$responsibleFieldName = $factory->getEntityFieldNameByMap(Item::FIELD_NAME_ASSIGNED);
		(new QueryParts\ResponsibleFilter)->apply($query, $params->userParams(), $responsibleFieldName);
	}

}