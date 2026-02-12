<?php

namespace Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Queries;

use Bitrix\Crm\Security\Role\Manage\RoleSelectionManager;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Serializers\AccessRightsEntitySerializer;

class QueryAccessRights
{
	public function __construct(
		private readonly RoleSelectionManager $manager,
	)
	{
	}

	public function execute(): array
	{
		$entities = $this->manager->buildModels();

		return (new AccessRightsEntitySerializer())->serialize($entities);
	}
}
