<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\BaseTrigger;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Activity\Trigger\ContextFields\TimemanStopWorktimeTrigger;
use Bitrix\Bizproc\Result;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPStopWorkTimeTrigger extends BaseTrigger
{
	private const PARAM_USER_IDS = 'USER_IDS';
	private const RETURN_PARAM_USER = 'USER';
	private const RETURN_PARAM_RECORD_ID = 'RECORD_ID';

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			self::PARAM_USER_IDS => null,
			self::RETURN_PARAM_USER => null,
			self::RETURN_PARAM_RECORD_ID => null,
		];

		$this->SetPropertiesTypes([
			self::RETURN_PARAM_USER => [
				'Type' => FieldType::USER,
			],
			self::RETURN_PARAM_RECORD_ID => [
				'Type' => FieldType::INT,
			],
		]);
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_USER_IDS => [
				'Name' => Loc::getMessage('STOP_WORK_TIME_TRIGGER_PROPERTY_USER_IDS'),
				'FieldName' => self::PARAM_USER_IDS,
				'Type' => FieldType::USER,
				'Required' => true,
				'Multiple' => true,
			],
		];
	}

	public static function validateProperties($arTestProperties = [], ?CBPWorkflowTemplateUser $user = null): array
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
				self::PARAM_USER_IDS => Loc::getMessage('STOP_WORK_TIME_TRIGGER_PROPERTY_USER_IDS_EMPTY'),
				default => '',
			},
		];
	}

	protected static function getModuleId(): ?string
	{
		return 'timeman';
	}

	public function execute(): int
	{
		if (!class_exists(TimemanStopWorktimeTrigger::class))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$context = $this->getRootActivity()->{CBPDocument::PARAM_TRIGGER_EVENT_DATA} ?? [];

		$userId = (int)($context[TimemanStopWorktimeTrigger::FIELD_USER_ID] ?? 0);

		if (!empty($userId))
		{
			$this->{self::RETURN_PARAM_USER} = 'user_' . $userId;
		}

		$recordId = (int)($context[TimemanStopWorktimeTrigger::FIELD_RECORD_ID] ?? 0);

		if ($recordId > 0)
		{
			$this->{self::RETURN_PARAM_RECORD_ID} = $recordId;
		}

		return CBPActivityExecutionStatus::Closed;
	}

	public function checkApplyRules(array $rules, TriggerParameters $parameters): Result
	{
		$allowedUserIds = CBPHelper::ExtractUsers($this->{self::PARAM_USER_IDS}, $this->getDocumentId());

		$userId = (int)$parameters->get(TimemanStopWorktimeTrigger::FIELD_USER_ID);

		if (!$this->checkUsersUseAbility($allowedUserIds, $userId))
		{
			return Result::createError(
				new Error(Loc::getMessage('STOP_WORK_TIME_TRIGGER_PROPERTY_USER_IDS_INCORRECT')),
			);
		}

		return Result::createOk();
	}

	private function checkUsersUseAbility(array $userIds, int $userId): bool
	{
		return in_array($userId, $userIds, true);
	}
}
