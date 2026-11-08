<?php

namespace Bitrix\Crm\Automation\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Bizproc\FieldType;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Integration;
use Bitrix\Crm\Integration\BizProc\Starter\Mixins\Dto\TriggerBindingDocumentsDto;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

Loc::loadMessages(__FILE__);

/**
 * The chat is closed for good: the session row is already saved when the trigger runs, so the node
 * carries the stored closing moment, reason, duration and message count instead of the moment of
 * execution. A farewell text or a rating request delays the actual close, and with it the run.
 */
class OpenLineCloseTrigger extends OpenLineTrigger
{
	public const REASON_OPERATOR = 'operator';
	public const REASON_CLIENT = 'client';
	public const REASON_REPLY_LIMIT = 'replyLimit';

	protected const RETURN_OL_CLOSE_DATE_TIME = 'OpenLineCloseDateTime';
	protected const RETURN_OL_CLOSE_REASON = 'OpenLineCloseReason';
	protected const RETURN_OL_CLOSE_TIME_SEC = 'OpenLineCloseTimeSec';
	protected const RETURN_OL_CLOSE_MESSAGE_COUNT = 'OpenLineCloseMessageCount';

	public static function getCode()
	{
		return 'OPENLINE_CLOSE';
	}

	/**
	 * The feature flag is the single point of the offer: a trigger that answers false here leaves
	 * {@see \Bitrix\Crm\Automation\Factory::getTriggerRegistry()}, and both surfaces that offer the node read
	 * that registry. A trigger already stored on a stage is looked up by its code on the target instead, so this
	 * answer does not stop a configured automation. The flag is asked first because it is only an option of the
	 * module, while the capability check behind it loads a module and looks up a method.
	 */
	public static function isEnabled()
	{
		return Feature::enabled(Feature\OpenLineCloseTriggerAvailability::class)
			&& Integration\OpenLineManager::isCloseTriggerEnabled();
	}

	public static function getName()
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_CLOSE_NAME');
	}

	public static function getDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_CLOSE_DESCRIPTION') ?? '';
	}

	/**
	 * The stored closing moment is a field of its own, so the node declares no execution date/time.
	 */
	public static function getReturnProperties(): array
	{
		return static::getOpenLineReturnProperties();
	}

	/**
	 * Values are narrowed to the declared fields, so an event field the parent adds cannot reach the
	 * node without a declaration of its own.
	 */
	public function getReturnValues(): ?array
	{
		$declared = array_flip(array_column(static::getReturnProperties(), 'Id'));

		return array_intersect_key(parent::getReturnValues() ?? [], $declared);
	}

	protected static function getBindingDocuments(TriggerBindingDocumentsDto $binding): array
	{
		return array_values(array_filter(
			parent::getBindingDocuments($binding),
			static fn(array $document): bool => static::isSupported((int)$document[0]),
		));
	}

	protected static function getOpenLineExtraReturnProperties(): array
	{
		return [
			[
				'Id' => self::RETURN_OL_CLOSE_DATE_TIME,
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_CLOSE_RETURN_DATE_CLOSE') ?? '',
				'Type' => FieldType::DATETIME,
				'Default' => null,
			],
			[
				'Id' => self::RETURN_OL_CLOSE_REASON,
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_CLOSE_RETURN_REASON') ?? '',
				'Type' => FieldType::SELECT,
				'Default' => null,
				'Options' => static::getCloseReasonOptions(),
			],
			[
				'Id' => self::RETURN_OL_CLOSE_TIME_SEC,
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_CLOSE_RETURN_TIME_CLOSE') ?? '',
				'Type' => FieldType::INT,
				'Default' => null,
			],
			[
				'Id' => self::RETURN_OL_CLOSE_MESSAGE_COUNT,
				'Name' => Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_CLOSE_RETURN_MESSAGE_COUNT') ?? '',
				'Type' => FieldType::INT,
				'Default' => null,
			],
		];
	}

	protected function buildOpenLineExtraReturnValues(): array
	{
		return [
			self::RETURN_OL_CLOSE_DATE_TIME => static::formatCloseDateTime($this->getInputData('DATE_CLOSE')),
			self::RETURN_OL_CLOSE_REASON => static::normalizeCloseReason($this->getInputData('CLOSE_REASON')),
			self::RETURN_OL_CLOSE_TIME_SEC => (int)$this->getInputData('TIME_CLOSE'),
			self::RETURN_OL_CLOSE_MESSAGE_COUNT => (int)$this->getInputData('MESSAGE_COUNT'),
		];
	}

	/**
	 * Only the reasons a close of the product actually carries: an option the field can never hold would
	 * be a condition that never matches. The open lines dictionary also knows spam, kept out of the
	 * automation altogether, and an automatic reason that needs STATUS_SILENTLY_CLOSE - a status no close
	 * path writes, so a close by timeout arrives as the client reason. Both codes are dropped by
	 * {@see normalizeCloseReason()} like any other undeclared one.
	 *
	 * @return array<string, string> Stored close reason code => localized name.
	 */
	protected static function getCloseReasonOptions(): array
	{
		return [
			self::REASON_OPERATOR => Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_CLOSE_REASON_OPERATOR') ?? '',
			self::REASON_CLIENT => Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_CLOSE_REASON_CLIENT') ?? '',
			self::REASON_REPLY_LIMIT => Loc::getMessage(
				'CRM_AUTOMATION_TRIGGER_OPENLINE_CLOSE_REASON_REPLY_LIMIT'
			) ?? '',
		];
	}

	/**
	 * The field is a select over {@see getCloseReasonOptions()}: its value has to belong to the declared
	 * options, so an unknown code is dropped. Dropping it empties the field and does not stop the run.
	 */
	protected static function normalizeCloseReason(mixed $reason): ?string
	{
		if (!is_string($reason))
		{
			return null;
		}

		return array_key_exists($reason, static::getCloseReasonOptions()) ? $reason : null;
	}

	/**
	 * Stored closing moment as a string in the culture format: a deferred start carries the RETURN
	 * values through a JSON channel, which turns a DateTime object into an empty array.
	 */
	protected static function formatCloseDateTime($timestamp): ?string
	{
		$timestamp = (int)$timestamp;
		if ($timestamp <= 0)
		{
			return null;
		}

		return DateTime::createFromTimestamp($timestamp)->format(DateTime::getFormat());
	}

	public static function getNodeName(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_CLOSE_NODE_NAME') ?? '';
	}

	public static function getNodeDescription(): string
	{
		return Loc::getMessage('CRM_AUTOMATION_TRIGGER_OPENLINE_CLOSE_NODE_DESCRIPTION') ?? '';
	}

	public static function getNodeColor(): int
	{
		return ActivityColorIndex::BLUE->value;
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
