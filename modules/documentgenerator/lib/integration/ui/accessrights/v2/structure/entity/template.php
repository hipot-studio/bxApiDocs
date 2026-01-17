<?php

namespace Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\Structure\Entity;

use Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\Structure\Action\Modify;
use Bitrix\DocumentGenerator\UserPermissions;
use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Structure\Entity;
use Bitrix\UI\AccessRights\V2\Control\Value\Variable;
use Bitrix\UI\AccessRights\V2\Control\Variables;
use Bitrix\UI\AccessRights\V2\Dto\AccessRightsBuilder\PermissionDto;
use Bitrix\UI\AccessRights\V2\Options\RightSection;

final class Template implements Entity
{
	public function getId(): string
	{
		return UserPermissions::ENTITY_TEMPLATES;
	}

	public function getTitle(): string
	{
		return UserPermissions::getEntityTitles()[$this->getId()];
	}

	public function getPermissions(): array
	{
		return [
			(new PermissionDto(
				action: new Modify(),
				control: (new Variables())
					->variables([
						new Variable(...$this->variable(UserPermissions::PERMISSION_NONE)),
						new Variable(...$this->variable(UserPermissions::PERMISSION_SELF)),
						new Variable(...$this->variable(UserPermissions::PERMISSION_DEPARTMENT)),
						new Variable(...$this->variable(UserPermissions::PERMISSION_ANY)),
					])
					->min(UserPermissions::PERMISSION_NONE)
					->max(UserPermissions::PERMISSION_ANY)
					->default(UserPermissions::PERMISSION_NONE)
					->empty(UserPermissions::PERMISSION_NONE)
					->nothingSelected(UserPermissions::PERMISSION_NONE),
			)),
		];
	}

	private function variable(mixed $permission): array
	{
		return [
			UserPermissions::getPermissionTitle($permission, UserPermissions::ENTITY_TEMPLATES),
			$permission,
		];
	}
}
