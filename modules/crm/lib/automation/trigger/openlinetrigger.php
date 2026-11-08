<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Main\Localization\Loc;
use Bitrix\Crm\Integration;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

class OpenLineTrigger extends BaseTrigger
{
	protected const EVENT_DATE_TIME_ID = 'EventDateTime';

	use OpenLineReturnTrait;

	protected static function getOpenLineReturnFieldIds(): array
	{
		return [
			self::RETURN_OL_CHAT_ID,
			self::RETURN_OL_CONFIG_ID,
			self::RETURN_OL_CLIENT_ID,
			self::RETURN_OL_OPERATOR_ID,
		];
	}

	/**
	 * The message is written by the client, and the operator behind the chat is already exposed as a
	 * field of its own, so the node names no initiator.
	 */
	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_EVENT_DATE_TIME') ?? ''
				),
			],
			static::getOpenLineReturnProperties()
		);
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], $this->buildOpenLineReturnValues(), [
			static::EVENT_DATE_TIME_ID => static::buildEventDateTimeValue(),
		]);
	}

	public static function isSupported($entityTypeId)
	{
		$unsupported = [\CCrmOwnerType::Quote, \CCrmOwnerType::SmartInvoice, \CCrmOwnerType::SmartDocument];
		if (in_array($entityTypeId, $unsupported, true))
		{
			return false;
		}

		return parent::isSupported($entityTypeId);
	}

	public static function isEnabled()
	{
		return Integration\OpenLineManager::isEnabled();
	}

	public static function getCode()
	{
		return 'OPENLINE';
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_NAME_2');
	}

	public function checkApplyRules(array $trigger)
	{
		return $this->checkCommonApplyRules($trigger) && $this->checkMessageTextApplyRule($trigger);
	}

	/**
	 * Base rules and the line selection: the part every open-line trigger shares, the outgoing one
	 * included, since it publishes no message text rule of its own.
	 */
	protected function checkCommonApplyRules(array $trigger): bool
	{
		if (!parent::checkApplyRules($trigger))
		{
			return false;
		}

		if (
			is_array($trigger['APPLY_RULES'])
			&& isset($trigger['APPLY_RULES']['config_id'])
			&& $trigger['APPLY_RULES']['config_id'] > 0
		)
		{
			return (int)$trigger['APPLY_RULES']['config_id'] === (int)$this->getInputData('CONFIG_ID');
		}

		return true;
	}

	private function checkMessageTextApplyRule(array $trigger): bool
	{
		$msg = $this->getInputData('MESSAGE');
		if (
			$msg
			&& isset($trigger['APPLY_RULES']['msg_text'])
			&& $trigger['APPLY_RULES']['msg_text'] !== ''
		)
		{
			$msgText = $msg['PLAIN_TEXT'] ?? $msg['TEXT'];
			if ($msgText !== '')
			{
				return (mb_stripos($msgText, $trigger['APPLY_RULES']['msg_text']) !== false);
			}

			return false;
		}

		return true;
	}

	protected static function getPropertiesMap(): array
	{
		return [
			[
				'Id' => 'config_id',
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_PROPERTY_CONFIG'),
				'Type' => 'select',
				'EmptyValueText' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_DEFAULT_CONFIG'),
				'Options' => static::getConfigList(),
			],
		];
	}

	protected static function getConfigList()
	{
		if (!static::isEnabled())
		{
			return [];
		}
		$configs = [];
		$orm = \Bitrix\ImOpenLines\Model\ConfigTable::getList(Array(
			'select' => ['ID', 'LINE_NAME'],
			'filter' => Array(
				'=TEMPORARY' => 'N'
			)
		));
		while ($config = $orm->fetch())
		{
			$configs[] = array(
				'value' => $config['ID'],
				'name' => $config['LINE_NAME']
			);
		}

		return $configs;
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_DESCRIPTION') ?? '';
	}

	public static function getGroup(): array
	{
		return ['clientCommunication'];
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::MESSAGE->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::CLIENT_COMMUNICATION->value];
	}
}
