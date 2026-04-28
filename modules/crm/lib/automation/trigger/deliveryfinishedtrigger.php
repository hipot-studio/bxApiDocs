<?php

namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
Use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

/**
 * Class DeliveryFinishedTrigger
 * @package Bitrix\Crm\Automation\Trigger
 */
class DeliveryFinishedTrigger extends BaseTrigger
{
	/**
	 * @inheritDoc
	 */
	public static function isSupported($entityTypeId)
	{
		return $entityTypeId === \CCrmOwnerType::Deal;
	}

	/**
	 * @inheritDoc
	 */
	public static function getCode()
	{
		return 'DELIVERY_FINISHED';
	}

	/**
	 * @inheritDoc
	 */
	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_DELIVERY_FINISHED_NAME_1');
	}

	public static function getGroup(): array
	{
		return ['delivery'];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_DELIVERY_FINISHED_DESCRIPTION') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_DELIVERY_FINISHED_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		$iconValue = 'o-package-receive';
		$icon = Outline::tryFrom($iconValue);

		if ($icon !== null)
		{
			return $icon->name;
		}

		return parent::getNodeIcon();
	}
}
