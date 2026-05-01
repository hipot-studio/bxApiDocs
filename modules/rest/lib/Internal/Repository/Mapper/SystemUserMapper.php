<?php

namespace Bitrix\Rest\Internal\Repository\Mapper;

use Bitrix\Rest\Internal\Entity\SystemUser;
use Bitrix\Rest\Internal\Models\EO_SystemUser;
use Bitrix\Rest\Internal\Entity\SystemUser\AccountType;
use Bitrix\Rest\Internal\Entity\SystemUser\ResourceType;

class SystemUserMapper
{
	public function convertFromOrm(EO_SystemUser $object): SystemUser
	{
		return new SystemUser(
			$object->getId(),
			$object->getUserId(),
			AccountType::from($object->getAccountType()),
			ResourceType::from($object->getResourceType()),
			$object->getResourceId(),
		);
	}
}