<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Main\Localization\Loc;
use Bitrix\Crm\Integration;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class OpenLineMessageTrigger extends OpenLineTrigger
{
	public static function getCode()
	{
		return 'OPENLINE_MSG';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_MESSAGE_NAME_1');
	}

	public static function getGroup(): array
	{
		return ['clientCommunication'];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_MESSAGE_DESCRIPTION') ?? '';
	}

	protected static function getPropertiesMap(): array
	{
		$map = parent::getPropertiesMap();
		$map[] = [
			'Id' => 'msg_text',
			'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_MESSAGE_PROPERTY_MSG_TEXT'),
			'Type' => 'string',
		];

		return $map;
	}

	public static function getNodeName(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_MESSAGE_NODE_NAME') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_MESSAGE_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::CLIENT_CHAT->name;
	}
}
