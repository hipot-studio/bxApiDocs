<?php

namespace Bitrix\Crm\Counter\QueryBuilder;

use Bitrix\Crm\Counter\QueryBuilder;
use Bitrix\Crm\Pseudoactivity\Entity\WaitTable;
use Bitrix\Crm\UserActivityTable;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * Idle counter.
 * Counts entities without activities and without wait entities.
 */
class idle extends QueryBuilder
{
    public function buildCompatible(Query $query): Query
    {
        if (self::SELECT_TYPE_ENTITIES === $this->getSelectType()) {
            $query->addSelect('ID', 'ENTY');
        } else {
            $query->registerRuntimeField('', new ExpressionField('QTY', 'COUNT(%s)', 'ID'));
            $query->addSelect('QTY');
        }

        $query->registerRuntimeField(
            '',
            new ReferenceField(
                'UA',
                UserActivityTable::getEntity(),
                [
                    '=ref.OWNER_ID' => 'this.ID',
                    '=ref.OWNER_TYPE_ID' => new SqlExpression($this->entityTypeId),
                    '=ref.USER_ID' => new SqlExpression(0),
                ],
                ['join_type' => 'LEFT']
            )
        );
        $query->addFilter('==UA.OWNER_ID', null);

        $query->registerRuntimeField(
            '',
            new ReferenceField(
                'W',
                WaitTable::getEntity(),
                [
                    '=ref.OWNER_ID' => 'this.ID',
                    '=ref.OWNER_TYPE_ID' => new SqlExpression($this->entityTypeId),
                    '=ref.COMPLETED' => new SqlExpression('?s', 'N'),
                ],
                ['join_type' => 'LEFT']
            )
        );
        $query->addFilter('==W.OWNER_ID', null);

        if (\CCrmOwnerType::Order !== $this->entityTypeId) {
            $assignedColumn = 'ASSIGNED_BY_ID';
        } else {
            $assignedColumn = 'RESPONSIBLE_ID';
        }

        $this->applyResponsibleFilter($query, $assignedColumn);

        return $query;
    }

    protected function getJoinType(): string
    {
        return Join::TYPE_LEFT;
    }

    protected function applyCounterTypeFilter(Query $query): void
    {
        $query->whereNull('B.ENTITY_ID');

        $query->registerRuntimeField(
            '',
            new ReferenceField(
                'W',
                WaitTable::getEntity(),
                [
                    '=ref.OWNER_ID' => 'this.ID',
                    '=ref.OWNER_TYPE_ID' => new SqlExpression($this->entityTypeId),
                    '=ref.COMPLETED' => new SqlExpression('?s', 'N'),
                ],
                ['join_type' => 'LEFT']
            )
        );
        $query->whereNull('W.OWNER_ID');

        if (\CCrmOwnerType::Order !== $this->entityTypeId) {
            $assignedColumn = 'ASSIGNED_BY_ID';
        } else {
            $assignedColumn = 'RESPONSIBLE_ID';
        }

        $this->applyResponsibleFilter($query, $assignedColumn);
    }

    protected function applyReferenceFilter(array &$referenceFilter): void
    {
        $referenceFilter['=ref.RESPONSIBLE_ID'] = new SqlExpression('?i', 0); // 0 means "All users"
    }
}
