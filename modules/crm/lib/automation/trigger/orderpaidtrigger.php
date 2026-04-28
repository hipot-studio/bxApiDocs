<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
Use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class OrderPaidTrigger extends BaseTrigger
{
	public static function isSupported($entityTypeId)
	{
		return
			$entityTypeId === \CCrmOwnerType::Deal
			|| \CCrmOwnerType::isUseDynamicTypeBasedApproach($entityTypeId)
		;
	}

	public static function getCode()
	{
		return 'ORDER_PAID';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_ORDER_PAID_NAME_1');
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_ORDER_PAID_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['payment'];
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_ORDER_PAID_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::PACKAGE->name;
	}
}
