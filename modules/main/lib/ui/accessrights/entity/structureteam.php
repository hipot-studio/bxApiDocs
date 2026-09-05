<?php

namespace Bitrix\Main\UI\AccessRights\Entity;

use Bitrix\HumanResources\Item\Node;
use Bitrix\Main\Access\AccessCode;

class StructureTeam extends EntityBase
{
	use HrStructureModelTrait;

	/**
	 * @return ?Node
	 */
	public function getModel()
	{
		return $this->model ?? null;
	}

	public function getType(): string
	{
		return AccessCode::TYPE_STRUCTURE_TEAM;
	}

	public function getName(): string
	{
		return $this->getModel()?->name ?? '';
	}

	public function getUrl(): string
	{
		return '';
	}

	public function getAvatar(int $width = 58, int $height = 58): ?string
	{
		return '';
	}
}
