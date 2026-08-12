<?php

namespace Bitrix\Crm\Security\Controller;

class Order extends Base
{
	public function getEntityTypeId(): int
	{
		return \CCrmOwnerType::Order;
	}

	public function isPermissionEntityTypeSupported($entityType): bool
	{
		if (!self::enabled())
		{
			return false;
		}

		return parent::isPermissionEntityTypeSupported($entityType);
	}

	protected function getSelectFields(): array
	{
		return [
			'ID',
			'RESPONSIBLE_ID',
		];
	}

	protected function extractAssignedByFromFields(array $fields): int
	{
		$assignedById = (int)($fields['RESPONSIBLE_ID'] ?? 0);
		if ($assignedById < 0)
		{
			$assignedById = 0;
		}

		return $assignedById;
	}

	public function register(string $permissionEntityType, int $entityId, ?RegisterOptions $options = null): void
	{
		if (self::enabled() && $options)
		{
			(new (\Bitrix\Crm\Security\Manager::getCompatibleController()))
				->register($permissionEntityType, $entityId, $options)
			;
		}

		parent::register($permissionEntityType, $entityId, $options);
	}


	public function unregister(string $permissionEntityType, int $entityId): void
	{
		if (self::enabled())
		{
			(new (\Bitrix\Crm\Security\Manager::getCompatibleController()))
				->unregister($permissionEntityType, $entityId)
			;
		}

		parent::unregister($permissionEntityType, $entityId);
	}

	public static function enabled(): bool
	{
		return	\Bitrix\Main\Config\Option::get('crm.agent', '~CRM_REBUILD_ORDER_SECURITY_ATTR', 'N') !== 'Y';
	}

	public static function enable(): void
	{
		\Bitrix\Main\Config\Option::delete('crm.agent', ['name' =>'~CRM_REBUILD_ORDER_SECURITY_ATTR']);
	}


}
