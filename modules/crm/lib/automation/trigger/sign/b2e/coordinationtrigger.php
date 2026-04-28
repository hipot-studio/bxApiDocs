<?php

namespace Bitrix\Crm\Automation\Trigger\Sign\B2e;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

final class CoordinationTrigger extends AbstractB2eDocumentTrigger
{
	public static function getCode(): string
	{
		return 'B2E_COORDINATION';
	}

	public static function getName(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COORDINATION_NAME') ?? '';
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COORDINATION_DESCRIPTION') ?? '';
	}

	public static function getNodeName(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COORDINATION_NODE_NAME') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_B2E_COORDINATION_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::ORANGE->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::LIKE->name;
	}
}
