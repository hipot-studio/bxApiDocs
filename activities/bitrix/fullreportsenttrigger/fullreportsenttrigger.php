<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\BaseTrigger;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Result;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\V2\Internal\Integration\Bizproc\FullReportSentTrigger;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPFullReportSentTrigger extends BaseTrigger
{
	private const PARAM_USER_IDS = 'USER_IDS';
	private const RETURN_PARAM_USER = 'USER';
	private const RETURN_PARAM_MANAGER = 'MANAGER';
	private const RETURN_PARAM_REPORT = 'REPORT';
	private const RETURN_PARAM_REPORT_EXTENDED = 'REPORT_EXTENDED';
	private const RETURN_PARAM_REPORT_ID = 'REPORT_ID';
	private const RETURN_PARAM_PERIOD_PHRASE = 'PERIOD_PHRASE';
	private const RETURN_PARAM_SOURCE_TYPE = 'SOURCE_TYPE';
	private const RETURN_PARAM_PERIOD_FROM = 'PERIOD_FROM';
	private const RETURN_PARAM_PERIOD_TO = 'PERIOD_TO';
	private const RETURN_PARAM_EVENT_DATE_TIME = 'EventDateTime';

	public function execute(): int
	{
		$context = $this->getRootActivity()->{\CBPDocument::PARAM_TRIGGER_EVENT_DATA} ?? [];
		$this->{self::RETURN_PARAM_EVENT_DATE_TIME} = $this->createDateTimeValue(
			$context[FullReportSentTrigger::FIELD_EVENT_DATE_TIME] ?? null,
		);

		$userId = (int)($context[FullReportSentTrigger::FIELD_USER_ID] ?? 0);
		if (!empty($userId))
		{
			$this->{self::RETURN_PARAM_USER} = 'user_' . $userId;
		}

		$managerId = (int)($context[FullReportSentTrigger::FIELD_MANAGER_ID] ?? 0);
		if (!empty($managerId))
		{
			$this->{self::RETURN_PARAM_MANAGER} = 'user_' . $managerId;
		}

		$this->{self::RETURN_PARAM_REPORT} = (string)($context[FullReportSentTrigger::FIELD_REPORT] ?? '');
		$this->{self::RETURN_PARAM_REPORT_EXTENDED} = (string)($context[FullReportSentTrigger::FIELD_REPORT_EXTENDED] ?? '');
		$this->{self::RETURN_PARAM_REPORT_ID} = (int)($context[FullReportSentTrigger::FIELD_REPORT_ID] ?? 0);
		$this->{self::RETURN_PARAM_PERIOD_PHRASE} = (string)($context[FullReportSentTrigger::FIELD_PERIOD_PHRASE] ?? '');
		$this->{self::RETURN_PARAM_SOURCE_TYPE} = (string)($context[FullReportSentTrigger::FIELD_SOURCE_TYPE] ?? '');
		$this->{self::RETURN_PARAM_PERIOD_FROM} = $this->createDateTimeValue(
			$context[FullReportSentTrigger::FIELD_PERIOD_FROM] ?? null,
		);
		$this->{self::RETURN_PARAM_PERIOD_TO} = $this->createDateTimeValue(
			$context[FullReportSentTrigger::FIELD_PERIOD_TO] ?? null,
		);

		return CBPActivityExecutionStatus::Closed;
	}

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			self::PARAM_USER_IDS => null,
			self::RETURN_PARAM_USER => null,
			self::RETURN_PARAM_MANAGER => null,
			self::RETURN_PARAM_REPORT => null,
			self::RETURN_PARAM_REPORT_EXTENDED => null,
			self::RETURN_PARAM_REPORT_ID => null,
			self::RETURN_PARAM_PERIOD_PHRASE => null,
			self::RETURN_PARAM_SOURCE_TYPE => null,
			self::RETURN_PARAM_PERIOD_FROM => null,
			self::RETURN_PARAM_PERIOD_TO => null,
			self::RETURN_PARAM_EVENT_DATE_TIME => null,
		];

		$this->SetPropertiesTypes([
			self::RETURN_PARAM_USER => [
				'Type' => FieldType::USER,
			],
			self::RETURN_PARAM_MANAGER => [
				'Type' => FieldType::USER,
			],
			self::RETURN_PARAM_REPORT => [
				'Type' => FieldType::TEXT,
			],
			self::RETURN_PARAM_REPORT_EXTENDED => [
				'Type' => FieldType::TEXT,
			],
			self::RETURN_PARAM_REPORT_ID => [
				'Type' => FieldType::INT,
			],
			self::RETURN_PARAM_PERIOD_PHRASE => [
				'Type' => FieldType::TEXT,
			],
			self::RETURN_PARAM_SOURCE_TYPE => [
				'Type' => FieldType::STRING,
			],
			self::RETURN_PARAM_PERIOD_FROM => [
				'Type' => FieldType::DATETIME,
			],
			self::RETURN_PARAM_PERIOD_TO => [
				'Type' => FieldType::DATETIME,
			],
			self::RETURN_PARAM_EVENT_DATE_TIME => [
				'Type' => FieldType::DATETIME,
			],
		]);
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_USER_IDS => [
				'Name' => Loc::getMessage('FULL_REPORT_SENT_TRIGGER_PROPERTY_USER_IDS'),
				'FieldName' => self::PARAM_USER_IDS,
				'Type' => FieldType::USER,
				'Multiple' => true,
			],
		];
	}

	public function checkApplyRules(array $rules, TriggerParameters $parameters): Result
	{
		if (CBPHelper::isEmptyValue($this->{self::PARAM_USER_IDS}))
		{
			return Result::createOk();
		}

		$userId = (int)$parameters->get(FullReportSentTrigger::FIELD_USER_ID);

		$allowedUserIds = CBPHelper::ExtractUsers(
			$this->{self::PARAM_USER_IDS},
			$this->getDocumentId(),
		);

		if (!in_array($userId, $allowedUserIds, true))
		{
			return Result::createError(
				new Error(Loc::getMessage('FULL_REPORT_SENT_TRIGGER_PROPERTY_USER_IDS_INCORRECT')),
			);
		}

		return Result::createOk();
	}

	protected static function getModuleId(): ?string
	{
		return 'timeman';
	}

	private function createDateTimeValue(mixed $value): ?DateTime
	{
		// CBPHelper reads every form a dispatcher may send, including the serialized bizproc datetime
		// whose trailing offset a plain DateTime constructor would drop.
		$timestamp = \CBPHelper::makeTimestamp($value);

		return $timestamp > 0 ? DateTime::createFromTimestamp($timestamp) : null;
	}
}
