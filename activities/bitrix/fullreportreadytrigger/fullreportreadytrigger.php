<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\BaseTrigger;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Result;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\V2\Internal\Integration\Bizproc\FullReportReadyTrigger;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPFullReportReadyTrigger extends BaseTrigger
{
	private const PARAM_USER_IDS = 'USER_IDS';
	private const RETURN_PARAM_USER = 'USER';
	private const RETURN_PARAM_MANAGER = 'MANAGER';
	private const RETURN_PARAM_EVENT_DATE_TIME = 'EventDateTime';

	public function execute(): int
	{
		$this->{self::RETURN_PARAM_EVENT_DATE_TIME} = (new DateTime())->format(DateTime::getFormat());

		$context = $this->getRootActivity()->{\CBPDocument::PARAM_TRIGGER_EVENT_DATA} ?? [];

		$userId = (int)($context[FullReportReadyTrigger::FIELD_USER_ID] ?? 0);
		if (!empty($userId))
		{
			$this->{self::RETURN_PARAM_USER} = 'user_' . $userId;
		}

		$managerId = (int)($context[FullReportReadyTrigger::FIELD_MANAGER_ID] ?? 0);
		if (!empty($managerId))
		{
			$this->{self::RETURN_PARAM_MANAGER} = 'user_' . $managerId;
		}

		return CBPActivityExecutionStatus::Closed;
	}

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			self::PARAM_USER_IDS => null,
			self::RETURN_PARAM_USER => null,
			self::RETURN_PARAM_MANAGER => null,
			self::RETURN_PARAM_EVENT_DATE_TIME => null,
		];

		$this->SetPropertiesTypes([
			self::RETURN_PARAM_USER => [
				'Type' => FieldType::USER,
			],
			self::RETURN_PARAM_MANAGER => [
				'Type' => FieldType::USER,
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
				'Name' => Loc::getMessage('FULL_REPORT_READY_TRIGGER_PROPERTY_USER_IDS'),
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

		$userId = (int)$parameters->get(FullReportReadyTrigger::FIELD_USER_ID);

		$allowedUserIds = CBPHelper::ExtractUsers(
			$this->{self::PARAM_USER_IDS},
			$this->getDocumentId(),
		);

		if (!in_array($userId, $allowedUserIds, true))
		{
			return Result::createError(
				new Error(Loc::getMessage('FULL_REPORT_READY_TRIGGER_PROPERTY_USER_IDS_INCORRECT')),
			);
		}

		return Result::createOk();
	}

	protected static function getModuleId(): ?string
	{
		return 'timeman';
	}
}
