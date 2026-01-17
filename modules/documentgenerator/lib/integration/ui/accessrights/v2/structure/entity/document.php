<?php

namespace Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\Structure\Entity;

use Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\Structure\Action\Modify;
use Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\Structure\Action\View;
use Bitrix\DocumentGenerator\UserPermissions;
use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Structure\Entity;
use Bitrix\UI\AccessRights\V2\Control\Toggler;
use Bitrix\UI\AccessRights\V2\Dto\AccessRightsBuilder\PermissionDto;

final class Document implements Entity
{
	public function getId(): string
	{
		return UserPermissions::ENTITY_DOCUMENTS;
	}

	public function getTitle(): string
	{
		return UserPermissions::getEntityTitles()[$this->getId()];
	}

	public function getPermissions(): array
	{
		return [
			new PermissionDto(new Modify(), new Toggler(UserPermissions::PERMISSION_ALLOW, '')),
			new PermissionDto(new View(), new Toggler(UserPermissions::PERMISSION_ALLOW, '')),
		];
	}
}
