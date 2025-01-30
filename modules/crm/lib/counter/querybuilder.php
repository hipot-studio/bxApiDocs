<?php

namespace Bitrix\Crm\Counter;

use Bitrix\Crm\Activity\Entity\EntityUncompletedActivityTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

abstract class querybuilder
{
    public const SELECT_TYPE_QUANTITY = 'QTY';
    public const SELECT_TYPE_ENTITIES = 'ENTY';

    protected int $entityTypeId;

    /**
     * @var int[]
     */
    protected array $userIds;
    protected string $selectType = self::SELECT_TYPE_QUANTITY;
    protected bool $useDistinct = true;

    public function __construct(int $entityTypeId, array $userIds = [])
    {
        $this->entityTypeId = $entityTypeId;
        $this->userIds = array_values(array_unique(array_map('intval', $userIds)));
    }

    public function getSelectType(): string
    {
        return $this->selectType;
    }

    public function setSelectType(string $selectType): self
    {
        $this->selectType = $selectType;

        return $this;
    }

    /**
     * @deprecated Should be used only if $this->isCompatibilityMode() return true;
     */
    public function isUseDistinct(): bool
    {
        return $this->useDistinct;
    }

    /**
     * @deprecated Should be used only if $this->isCompatibilityMode() return true;
     */
    public function setUseDistinct(bool $useDistinct): self
    {
        $this->useDistinct = $useDistinct;

        return $this;
    }

    public function build(Query $query): Query
    {
        if ($this->isCompatibilityMode()) {
            return $this->buildCompatible($query);
        }
        $referenceFilter = [
            '=ref.ENTITY_ID' => 'this.ID',
            '=ref.ENTITY_TYPE_ID' => new SqlExpression($this->entityTypeId),
        ];

        $this->applyReferenceFilter($referenceFilter);

        $query->registerRuntimeField(
            '',
            new ReferenceField(
                'B',
                EntityUncompletedActivityTable::getEntity(),
                $referenceFilter,
                ['join_type' => $this->getJoinType()]
            )
        );

        $this->applyCounterTypeFilter($query);

        if (self::SELECT_TYPE_ENTITIES === $this->getSelectType()) {
            $query->addSelect('ID', 'ENTY');
            if (\count($this->userIds) > 1) {
                $query->addGroup('B.ENTITY_ID');
            }
        } else {
            $query->registerRuntimeField('', new ExpressionField('QTY', 'COUNT(DISTINCT %s)', 'ID'));
            $query->addSelect('QTY');
        }

        return $query;
    }

    protected function applyResponsibleFilter(Query $query, string $responsibleFieldName)
    {
        if (!empty($this->userIds)) {
            if (\count($this->userIds) > 1) {
                $query->whereIn($responsibleFieldName, $this->userIds);
            } else {
                $query->where($responsibleFieldName, $this->userIds[0]);
            }
        }
    }

    /**
     * Compatibility mode used while \Bitrix\Crm\Activity\Entity\EntityUncompletedActivityTable is not completely filled with data.
     */
    protected function isCompatibilityMode(): bool
    {
        return 'Y' !== Option::get('crm', 'enable_entity_uncompleted_act', 'Y');
    }

    protected function getJoinType(): string
    {
        return Join::TYPE_INNER;
    }

    protected function applyReferenceFilter(array &$referenceFilter): void {}

    protected function applyCounterTypeFilter(Query $query): void {}

    abstract protected function buildCompatible(Query $query): Query;
}
