<?php
namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Bizproc\FieldType;
use Bitrix\Crm\Feature;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

/**
 * Outgoing counterpart of OPENLINE_MSG: an employee wrote to the client in an open line.
 *
 * The author of the message and the operator the session is currently assigned to are different
 * actors and may differ, so the node names both: the author comes from the dispatcher as
 * initiatorUserId, the operator from the session as OPERATOR_ID. The node has no message text rule,
 * so only the shared part of the open-line apply rules is used.
 */
class OpenLineOutgoingMessageTrigger extends OpenLineTrigger
{
	protected const EVENT_INITIATOR_ID = 'Initiator';
	protected const RETURN_OL_MESSAGE_TEXT = 'MessageText';

	public static function getCode()
	{
		return 'OPENLINE_OUTGOING_MSG';
	}

	/**
	 * The feature flag is the single point of the offer: a trigger that answers false here leaves
	 * {@see \Bitrix\Crm\Automation\Factory::getTriggerRegistry()}, and both surfaces that offer the node read
	 * that registry. A trigger already stored on a stage is looked up by its code on the target instead, so this
	 * answer does not stop a configured automation. The flag is asked first because it is only an option of the
	 * module, while the open line check behind it loads a module.
	 */
	public static function isEnabled()
	{
		return Feature::enabled(Feature\OpenLineOutgoingMessageTriggerAvailability::class)
			&& parent::isEnabled();
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_OUTGOING_MESSAGE_NAME') ?? '';
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_OUTGOING_MESSAGE_DESCRIPTION') ?? '';
	}

	public static function getReturnProperties(): array
	{
		return array_merge(
			[
				static::getEventInitiatorProperty(),
				static::getEventDateTimeProperty(
					Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_OUTGOING_MESSAGE_EVENT_DATE_TIME') ?? '',
				),
			],
			static::getOpenLineReturnProperties(),
		);
	}

	public function getReturnValues(): ?array
	{
		return array_merge(parent::getReturnValues() ?? [], [
			static::EVENT_INITIATOR_ID => $this->buildEventInitiatorValue(),
		]);
	}

	protected static function getOpenLineExtraReturnProperties(): array
	{
		return [
			[
				'Id' => self::RETURN_OL_MESSAGE_TEXT,
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_OUTGOING_MESSAGE_RETURN_TEXT') ?? '',
				'Type' => FieldType::TEXT,
				'Default' => null,
			],
		];
	}

	/**
	 * The text the employee typed, before the transport adds the operator signature, attachment codes
	 * and the rest of its own markup. A message carrying only an attachment has no text of its own.
	 */
	protected function buildOpenLineExtraReturnValues(): array
	{
		$message = $this->getInputData('MESSAGE');
		$text = is_array($message) ? ($message['PLAIN_TEXT'] ?? $message['TEXT'] ?? '') : '';

		return [
			self::RETURN_OL_MESSAGE_TEXT => (string)$text,
		];
	}

	public function checkApplyRules(array $trigger)
	{
		return $this->checkCommonApplyRules($trigger);
	}

	public static function getNodeName(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_OUTGOING_MESSAGE_NODE_NAME') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_OUTGOING_MESSAGE_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::GREEN->value;
	}

	public static function getNodeIcon(): string
	{
		return Outline::CLIENT_CHAT->name;
	}

	public static function getNodeGroups(): array
	{
		return [ActivityGroup::CLIENT_COMMUNICATION->value];
	}
}
