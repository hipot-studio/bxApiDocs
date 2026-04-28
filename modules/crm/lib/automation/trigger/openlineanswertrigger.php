<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Main\Localization\Loc;
use Bitrix\Crm\Integration;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class OpenLineAnswerTrigger extends OpenLineTrigger
{
	public static function getCode()
	{
		return 'OPENLINE_ANSWER';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_ANSWER_NAME_1');
	}

	public function setInputData($data)
	{
		if (isset($data['ANSWER_TIME_SEC']) && is_callable([$this, 'setReturnValues']))
		{
			$this->setReturnValues(['OpenLineAnswerTimeSec' => $data['ANSWER_TIME_SEC']]);
		}
		return parent::setInputData($data);
	}

	public static function getReturnProperties(): array
	{
		return [
			[
				'Id' => 'OpenLineAnswerTimeSec',
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_ANSWER_RETURN_ANSWER_TIME'),
				'Type' => 'int',
				'Default' => null,
			]
		];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_ANSWER_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['clientCommunication'];
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_ANSWER_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::BLUE->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::ADD_CHAT->name;
	}
}
