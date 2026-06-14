<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Result;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Slider\Path\TaskPathMaker;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPTasksExpiredTrigger extends Activity\BaseTrigger
{
	private const PARAM_PROJECT_ID = 'PROJECT_ID';
	private const PARAM_GROUP_ID = 'GROUP_ID';
	private const RETURN_PARAM_TASK_ID = 'TASK_ID';
	private const RETURN_PARAM_TASK_TITLE = 'TASK_TITLE';
	private const RETURN_PARAM_TASK_CREATED_DATE = 'TASK_CREATED_DATE';
	private const RETURN_PARAM_TASK_RESPONSIBLE = 'TASK_RESPONSIBLE';
	private const RETURN_PARAM_TASK_URL = 'TASK_URL';
	private const FIELD_TASK_ID = 'ID';
	private const FIELD_TASK_TITLE = 'TITLE';
	private const FIELD_TASK_CREATED_DATE = 'CREATED_DATE';
	private const FIELD_TASK_RESPONSIBLE_ID = 'RESPONSIBLE_ID';

	public function execute(): int
	{
		$context = $this->getRootActivity()->{\CBPDocument::PARAM_TRIGGER_EVENT_DATA} ?? [];

		$taskId = (int)($context[self::FIELD_TASK_ID] ?? 0);
		$taskTitle = (string)($context[self::FIELD_TASK_TITLE] ?? '');
		$taskCreateDate = (int)($context[self::FIELD_TASK_CREATED_DATE] ?? 0);
		$taskResponsibleId = (int)($context[self::FIELD_TASK_RESPONSIBLE_ID] ?? 0);

		if (!empty($taskId))
		{
			$this->{self::RETURN_PARAM_TASK_ID} = $taskId;
		}

		if (!empty($taskTitle))
		{
			$this->{self::RETURN_PARAM_TASK_TITLE} = $taskTitle;
		}

		if (!empty($taskCreateDate))
		{
			$this->{self::RETURN_PARAM_TASK_CREATED_DATE} = DateTime::createFromTimestamp($taskCreateDate);
		}

		if (!empty($taskResponsibleId))
		{
			$this->{self::RETURN_PARAM_TASK_RESPONSIBLE} = 'user_'.$taskResponsibleId;
		}

		$this->{self::RETURN_PARAM_TASK_URL} = $this->makeTaskUrl($taskId);

		return CBPActivityExecutionStatus::Closed;
	}

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			self::PARAM_PROJECT_ID => null,
			self::RETURN_PARAM_TASK_ID => null,
			self::RETURN_PARAM_TASK_TITLE => null,
			self::RETURN_PARAM_TASK_CREATED_DATE => null,
			self::RETURN_PARAM_TASK_RESPONSIBLE => null,
			self::RETURN_PARAM_TASK_URL => null,
		];

		$this->setPropertiesTypes([
			self::RETURN_PARAM_TASK_ID => [
				'Type' => FieldType::INT
			],
			self::RETURN_PARAM_TASK_TITLE => [
				'Type' => FieldType::STRING
			],
			self::RETURN_PARAM_TASK_CREATED_DATE => [
				'Type' => FieldType::DATETIME
			],
			self::RETURN_PARAM_TASK_RESPONSIBLE => [
				'Type' => FieldType::USER
			],
			self::RETURN_PARAM_TASK_URL => [
				'Type' => FieldType::STRING,
			],
		]);
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_PROJECT_ID => [
				'Name' => Loc::getMessage('TASKS_EXPIRED_TRIGGER_PROPERTY_PROJECT_ID'),
				'FieldName' => self::PARAM_PROJECT_ID,
				'Type' => FieldType::SELECT,
				'Options' => self::getProjectOptions(),
				'Required' => true,
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
				self::PARAM_PROJECT_ID => Loc::getMessage('TASKS_EXPIRED_TRIGGER_PROPERTY_PROJECT_ID_EMPTY'),
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
	): Activity\PropertiesDialog
	{
		$dialog = new Activity\PropertiesDialog(
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
		$eventProjectId = (int)$parameters->get(self::PARAM_GROUP_ID);
		$configProjectId = (int)$this->{self::PARAM_PROJECT_ID};

		if ($eventProjectId !== $configProjectId)
		{
			return Result::createError(
				new  Error(
					Loc::getMessage('TASKS_EXPIRED_TRIGGER_PROPERTY_PROJECT_ID_INCORRECT'),
					'',
				)
			);
		}

		return Result::createOk();
	}

	private static function getProjectOptions(): array
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return [];
		}

		global $USER;

		$groupIterator = CSocNetGroup::GetList(
			['NAME' => 'ASC'],
			['ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => $USER->GetId()],
			false,
			false,
			['ID', 'NAME']
		);
		$groups = [];

		while ($group = $groupIterator->GetNext())
		{
			$groups[$group['ID']] = "[{$group['ID']}]" . htmlspecialcharsback(Emoji::decode($group['NAME']));
		}

		return $groups;
	}

	protected static function getModuleId(): ?string
	{
		return 'tasks';
	}

	private function makeTaskUrl(int $taskId): ?string
	{
		if ($taskId <= 0 || !Loader::includeModule('tasks'))
		{
			return null;
		}

		$path = TaskPathMaker::getPath([
			'group_id' => (int)$this->{self::PARAM_PROJECT_ID},
			'task_id' => $taskId,
		]);

		if (empty($path))
		{
			return null;
		}

		return '/' . ltrim($path, '/');
	}
}
