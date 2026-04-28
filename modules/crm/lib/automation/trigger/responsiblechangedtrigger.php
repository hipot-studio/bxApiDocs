<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

class ResponsibleChangedTrigger extends BaseTrigger
{
	public static function getCode()
	{
		return 'RESP_CHANGED';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_RESPONSIBLE_CHANGED_NAME_1');
	}

	public static function getGroup(): array
	{
		return ['elementControl'];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_RESPONSIBLE_CHANGED_DESCRIPTION') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_RESPONSIBLE_CHANGED_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::BLUE->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::PERSON->name;
	}
}
