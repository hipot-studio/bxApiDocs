<?php
namespace Bitrix\Crm\Automation\Trigger\Sign;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class AllMembersSignedTrigger extends InitiatorSignedTrigger
{
	public static function getCode()
	{
		return 'SIGN_FINAL_SIGNING';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_ALL_MEMBERS_SIGNED_NAME_2');
	}

	public static function getGroup(): array
	{
		return ['paperwork'];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_ALL_MEMBERS_SIGNED_DESCRIPTION') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_SIGN_ALL_MEMBERS_SIGNED_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::BLUE->value;
	}

	public static function getNodeIcon(): string
	{
		$iconValue = 'o-three-persons-check';
		$icon = Outline::tryFrom($iconValue);

		if ($icon !== null)
		{
			return $icon->name;
		}

		return parent::getNodeIcon();
	}
}
