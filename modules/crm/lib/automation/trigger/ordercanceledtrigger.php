<?php

namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
Use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class OrderCanceledTrigger extends BaseTrigger
{
	public static function isSupported($entityTypeId)
	{
		return ($entityTypeId === \CCrmOwnerType::Order);
	}

	public static function getCode()
	{
		return 'ORDER_CANCELED';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_ORDER_CANCELED_NAME_1');
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_ORDER_CANCELED_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['delivery'];
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_ORDER_CANCELED_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::BLUE->value;
	}

	public static function getNodeIcon(): string
	{
		$iconValue = 'o-package-cancel';
		$icon = Outline::tryFrom($iconValue);

		if ($icon !== null)
		{
			return $icon->name;
		}

		return parent::getNodeIcon();
	}
}
