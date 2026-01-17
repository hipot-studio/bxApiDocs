<?php

namespace Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\Structure\Action;

use Bitrix\DocumentGenerator\UserPermissions;
use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Structure\Action;

final class Modify implements Action
{
	public function getId(): string
	{
		return UserPermissions::ACTION_MODIFY;
	}

	public function getTitle(): string
	{
		return UserPermissions::getActionTitles()[$this->getId()];
	}
}
