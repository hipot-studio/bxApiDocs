<?php

namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

class CallBackTrigger extends WebFormTrigger
{
	public static function getCode()
	{
		return 'CALLBACK';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_CALLBACK_NAME_1');
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_CALLBACK_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['clientCommunication'];
	}

	protected static function getFormList(array $filter = []): array
	{
		return parent::getFormList(['=IS_CALLBACK_FORM' => 'Y']);
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_CALLBACK_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::FEEDBACK_FORM->name;
	}
}
