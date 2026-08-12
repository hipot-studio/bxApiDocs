<?php

namespace Bitrix\Crm\Security\Controller;

class OrderPermissionAttributesMaker
{
	public function make(int $userId): array
	{
		return \Bitrix\Crm\Service\Container::getInstance()
			->getUserPermissions($userId)
			->getAttributesProvider()
			->getEntityAttributes()
		;
	}
}
