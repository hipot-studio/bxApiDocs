<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
Use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class VisitTrigger extends BaseTrigger
{
	public static function isSupported($entityTypeId)
	{
		if ($entityTypeId === \CCrmOwnerType::Quote || $entityTypeId === \CCrmOwnerType::SmartInvoice)
		{
			return false;
		}

		return parent::isSupported($entityTypeId);
	}

	protected static function areDynamicTypesSupported(): bool
	{
		return false;
	}

	public static function isEnabled()
	{
		return \Bitrix\Crm\Activity\Provider\Visit::isAvailable();
	}

	public static function getCode()
	{
		return 'VISIT';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_VISIT_NAME_1');
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_VISIT_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['other'];
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_VISIT_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::BLUE->value;
	}

	public static function getNodeIcon(): string
	{
		$iconValue = 'o-video-record-2';
		$icon = Outline::tryFrom($iconValue);

		if ($icon !== null)
		{
			return $icon->name;
		}

		return parent::getNodeIcon();
	}
}
