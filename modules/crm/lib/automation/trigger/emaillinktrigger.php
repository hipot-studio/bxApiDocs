<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
Use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class EmailLinkTrigger extends BaseTrigger
{
	public static function getCode()
	{
		return 'EMAIL_LINK';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_LINKHOOK_NAME_1');
	}

	public function checkApplyRules(array $trigger)
	{
		if (!parent::checkApplyRules($trigger))
		{
			return false;
		}

		if (
			is_array($trigger['APPLY_RULES'])
			&& !empty($trigger['APPLY_RULES']['url'])
		)
		{
			$inputUrl = (string) $this->getInputData('URL');
			$triggerUrl = (string) $trigger['APPLY_RULES']['url'];

			return (mb_strpos($inputUrl, $triggerUrl) === 0);
		}
		return true;
	}

	protected static function getPropertiesMap(): array
	{
		return [
			[
				'Id' => 'url',
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_LINKHOOK_URL'),
				'Placeholder' => 'https://example.com',
				'Type' => 'text',
			]
		];
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_LINKHOOK_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['clientCommunication'];
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_LINKHOOK_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::LINK->name;
	}
}
