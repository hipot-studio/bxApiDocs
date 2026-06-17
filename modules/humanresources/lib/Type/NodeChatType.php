<?php

namespace Bitrix\HumanResources\Type;

use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Internals\Trait\ValuesTrait;

enum NodeChatType: string
{
	use \Bitrix\HumanResources\Internals\Trait\ValuesTrait;

	case Chat = 'CHAT';
	case Channel = 'CHANNEL';
	case Collab = 'COLLAB';

	/**
	 * Returns the edit action ID for this communication type and node type.
	 *
	 * @see \Bitrix\HumanResources\Access\StructureActionDictionary
	 */
	public function getEditActionId(NodeEntityType $nodeType): string
	{
		return match ($this)
		{
			self::Chat => $nodeType === NodeEntityType::TEAM
				? StructureActionDictionary::ACTION_TEAM_CHAT_EDIT
				: StructureActionDictionary::ACTION_DEPARTMENT_CHAT_EDIT,
			self::Channel => $nodeType === NodeEntityType::TEAM
				? StructureActionDictionary::ACTION_TEAM_CHANNEL_EDIT
				: StructureActionDictionary::ACTION_DEPARTMENT_CHANNEL_EDIT,
			self::Collab => $nodeType === NodeEntityType::TEAM
				? StructureActionDictionary::ACTION_TEAM_COLLAB_EDIT
				: StructureActionDictionary::ACTION_DEPARTMENT_COLLAB_EDIT,
		};
	}
}