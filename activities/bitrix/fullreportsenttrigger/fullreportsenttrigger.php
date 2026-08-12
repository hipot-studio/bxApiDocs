<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\BaseTrigger;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Result;
use Bitrix\Main\Localization\Loc;
use Bitrix\Timeman\V2\Internal\Integration\Bizproc\FullReportSentTrigger;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPFullReportSentTrigger extends BaseTrigger
{
	private const PARAM_USER_IDS = 'USER_IDS';
	private const RETURN_PARAM_USER = 'USER';
	private const RETURN_PARAM_REPORT = 'REPORT';
	private const RETURN_PARAM_REPORT_EXTENDED = 'REPORT_EXTENDED';

	public function execute(): int
	{
		$context = $this->getRootActivity()->{\CBPDocument::PARAM_TRIGGER_EVENT_DATA} ?? [];

		$userId = (int)($context[FullReportSentTrigger::FIELD_USER_ID] ?? 0);
		if (!empty($userId))
		{
			$this->{self::RETURN_PARAM_USER} = 'user_' . $userId;
		}

		$this->{self::RETURN_PARAM_REPORT} = (string)($context[FullReportSentTrigger::FIELD_REPORT] ?? '');
		$this->{self::RETURN_PARAM_REPORT_EXTENDED} = (string)($context[FullReportSentTrigger::FIELD_REPORT_EXTENDED] ?? '');

		return CBPActivityExecutionStatus::Closed;
	}

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			self::PARAM_USER_IDS => null,
			self::RETURN_PARAM_USER => null,
			self::RETURN_PARAM_REPORT => null,
			self::RETURN_PARAM_REPORT_EXTENDED => null,
		];

		$this->SetPropertiesTypes([
			self::RETURN_PARAM_USER => [
				'Type' => FieldType::USER,
			],
			self::RETURN_PARAM_REPORT => [
				'Type' => FieldType::TEXT,
			],
			self::RETURN_PARAM_REPORT_EXTENDED => [
				'Type' => FieldType::TEXT,
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
}
