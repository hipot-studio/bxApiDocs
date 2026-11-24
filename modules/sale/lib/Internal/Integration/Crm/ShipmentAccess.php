<?php

namespace Bitrix\Sale\Internal\Integration\Crm;

use Bitrix\Crm;
use Bitrix\Main\DB\SqlExpression;

final class ShipmentAccess
{
	public function __construct(private readonly int $userId)
	{
	}

	public function canRead(int $shipmentId): bool
	{
		return Crm\Service\Container::getInstance()->getUserPermissions($this->userId)
			->item()->canRead(\CCrmOwnerType::OrderShipment, $shipmentId)
		;
	}

	public function getReadFilter(): array
	{
		$userPermissions = Crm\Service\Container::getInstance()->getUserPermissions($this->userId);
		if (\CCrmSaleHelper::isWithOrdersMode())
		{
			return $userPermissions->itemsList()->applyAvailableItemsFilter(
				[],
				[\CCrmOwnerType::OrderName],
				Crm\Service\UserPermissions::OPERATION_READ,
				'ORDER_ID',
			);
		}

		$filter = ['LOGIC' => 'OR'];
		$ownerTypes = array_merge(\CCrmOwnerType::GetAll(), [\CCrmOwnerType::Order]);
		$dynamicTypes = Crm\Service\Container::getInstance()->getDynamicTypesMap()->load([
			'isLoadStages' => false,
			'isLoadCategories' => false,
		])->getTypes();
		foreach ($dynamicTypes as $dynamicType)
		{
			$ownerTypes[] = $dynamicType->getEntityTypeId();
		}

		foreach ($ownerTypes as $ownerTypeId)
		{
			$permissionTypes = (new Crm\Category\PermissionEntityTypeHelper($ownerTypeId))
				->getAllPermissionEntityTypesForEntity()
			;
			if ($permissionTypes === [])
			{
				continue;
			}

			$ownerFilter = $userPermissions->itemsList()->applyAvailableItemsFilter(
				[],
				$permissionTypes,
				Crm\Service\UserPermissions::OPERATION_READ,
				'OWNER_ID',
			);
			if ($ownerFilter === ['@OWNER_ID' => [0]])
			{
				continue;
			}

			$bindingQuery = Crm\Binding\OrderEntityTable::query()
				->setSelect(['ORDER_ID'])
				->setFilter([
					['=OWNER_TYPE_ID' => $ownerTypeId],
					$ownerFilter,
				])
			;
			$filter[] = ['@ORDER_ID' => new SqlExpression($bindingQuery->getQuery())];
		}

		if ($userPermissions->entityType()->canReadItems(\CCrmOwnerType::Deal))
		{
			$bindingsQuery = Crm\Binding\OrderEntityTable::query()->setSelect(['ORDER_ID']);
			$filter[] = ['!@ORDER_ID' => new SqlExpression($bindingsQuery->getQuery())];
		}

		return count($filter) > 1 ? $filter : ['=ID' => 0];
	}
}
