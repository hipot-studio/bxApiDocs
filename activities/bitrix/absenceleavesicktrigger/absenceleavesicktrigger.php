<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Activity\Trigger\ContextFields\AbsenceBaseTrigger;
use Bitrix\Bizproc\Result;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPAbsenceLeaveSickTrigger extends \Bitrix\Bizproc\Activity\BaseTrigger
{
	private const PARAM_USER_IDS = 'USER_IDS';
	private const RETURN_PARAM_USER = 'USER';
	private const RETURN_PARAM_ACTIVE_FROM = 'ACTIVE_FROM';
	private const RETURN_PARAM_ACTIVE_TO = 'ACTIVE_TO';

	public function execute(): int
	{
		if (!class_exists(\Bitrix\Bizproc\Public\Activity\Trigger\ContextFields\AbsenceBaseTrigger::class))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$context = $this->getRootActivity()->{\CBPDocument::PARAM_TRIGGER_EVENT_DATA} ?? [];
		$userId = (int)($context[AbsenceBaseTrigger::FIELD_USER_ID] ?? 0);
		$activeFrom = (string)($context[AbsenceBaseTrigger::FIELD_ACTIVE_FROM] ?? '');
		$activeTo = (string)($context[AbsenceBaseTrigger::FIELD_ACTIVE_TO] ?? '');

		if (!empty($userId))
		{
			$this->{self::RETURN_PARAM_USER} = 'user_' . $userId;
		}

		if (DateTime::isCorrect($activeFrom))
		{
			$this->{self::RETURN_PARAM_ACTIVE_FROM} = new DateTime($activeFrom);
		}

		if (DateTime::isCorrect($activeTo))
		{
			$this->{self::RETURN_PARAM_ACTIVE_TO} = new DateTime($activeTo);
		}

		return CBPActivityExecutionStatus::Closed;
	}

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			self::PARAM_USER_IDS => null,
			self::RETURN_PARAM_USER => null,
			self::RETURN_PARAM_ACTIVE_FROM => null,
			self::RETURN_PARAM_ACTIVE_TO => null,
		];

		$this->SetPropertiesTypes([
			self::RETURN_PARAM_USER => ['Type' => FieldType::USER],
			self::RETURN_PARAM_ACTIVE_FROM => ['Type' => FieldType::DATETIME],
			self::RETURN_PARAM_ACTIVE_TO => ['Type' => FieldType::DATETIME],
		]);
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_USER_IDS => [
				'Name' => Loc::getMessage('TIMEMAN_ABSENCE_LEAVE_SICK_TRIGGER_PROPERTY_USER_IDS'),
				'FieldName' => self::PARAM_USER_IDS,
				'Type' => FieldType::USER,
				'Required' => true,
				'Multiple' => true,
			],
		];
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];
		foreach (self::getPropertiesMap([]) as $id => $property)
		{
			if (!empty($property['Required']) && empty($arTestProperties[$id]))
			{
				$errors[] = self::makeEmptyError($id);
			}
		}

		return array_merge($errors, parent::ValidateProperties($arTestProperties, $user));
	}

	private static function makeEmptyError(string $property): array
	{
		return [
			'code' => 'NotExist',
			'message' => match ($property)
			{
				self::PARAM_USER_IDS => Loc::getMessage('TIMEMAN_ABSENCE_LEAVE_SICK_TRIGGER_PROPERTY_USER_IDS_EMPTY'),
				default => '',
			},
		];
	}

	public static function getPropertiesDialog(
		$documentType,
		$activityName,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$arCurrentValues = null,
		$formName = "",
		$popupWindow = null,
		$siteId = '',
	): PropertiesDialog
	{
		$dialog = new PropertiesDialog(
			__FILE__, [
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $arCurrentValues,
			'formName' => $formName,
			'siteId' => $siteId,
		],
		);

		$dialog->setMap(static::getPropertiesMap($documentType));

		return $dialog;
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$arCurrentValues,
		&$errors,
	): bool
	{
		$properties = [];
		$errors = [];

		$documentService = CBPRuntime::getRuntime()->getDocumentService();
		foreach (static::getPropertiesMap($documentType) as $id => $property)
		{
			$value = $documentService->getFieldInputValue(
				$documentType,
				$property,
				$property['FieldName'],
				$arCurrentValues,
				$errors
			);

			if (!empty($errors))
			{
				return false;
			}

			$properties[$id] = $value;
		}

		$errors = self::validateProperties(
			$properties,
			new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser)
		);

		if ($errors)
		{
			return false;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::findActivityByName($workflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}

	public function checkApplyRules(array $rules, TriggerParameters $parameters): Result
	{
		$userId = (int)$parameters->get(AbsenceBaseTrigger::FIELD_USER_ID);

		$allowedUserIds = CBPHelper::ExtractUsers(
			$this->{self::PARAM_USER_IDS},
			$this->getDocumentId()
		);

		if (!$this->checkUsersUseAbility($allowedUserIds, $userId))
		{
			return Result::createError(
				new Error(Loc::getMessage('TIMEMAN_ABSENCE_LEAVE_SICK_TRIGGER_PROPERTY_USER_IDS_INCORRECT'))
			);
		}

		return Result::createOk();
	}

	private function checkUsersUseAbility(array $userIds, int $userId): bool
	{
		return in_array($userId, $userIds, true);
	}
}
