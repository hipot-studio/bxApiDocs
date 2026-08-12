<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\BaseTrigger;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Result;
use Bitrix\Main\Localization\Loc;
use Bitrix\Timeman\Integration\Bizproc\StopWorktimeTrigger;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPStopWorkTimeTrigger extends BaseTrigger
{
	private const PARAM_USER_IDS = 'USER_IDS';
	private const PARAM_IS_FIRST_STOP = 'IS_FIRST_STOP_FILTER';
	private const RETURN_PARAM_USER = 'USER';
	private const RETURN_PARAM_RECORD_ID = 'RECORD_ID';
	private const RETURN_PARAM_IS_FIRST_STOP = 'IS_FIRST_STOP';

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			self::PARAM_USER_IDS => null,
			self::PARAM_IS_FIRST_STOP => null,
			self::RETURN_PARAM_USER => null,
			self::RETURN_PARAM_RECORD_ID => null,
			self::RETURN_PARAM_IS_FIRST_STOP => null,
		];

		$this->SetPropertiesTypes([
			self::RETURN_PARAM_USER => [
				'Type' => FieldType::USER,
			],
			self::RETURN_PARAM_RECORD_ID => [
				'Type' => FieldType::INT,
			],
			self::RETURN_PARAM_IS_FIRST_STOP => [
				'Type' => FieldType::BOOL,
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
				'Required' => false,
				'Multiple' => true,
			],
			self::PARAM_IS_FIRST_STOP => [
				'Name' => Loc::getMessage('STOP_WORK_TIME_TRIGGER_PROPERTY_IS_FIRST_STOP'),
				'FieldName' => self::PARAM_IS_FIRST_STOP,
				'Type' => FieldType::SELECT,
				'Options' => [
					'' => Loc::getMessage('STOP_WORK_TIME_TRIGGER_PROPERTY_IS_FIRST_STOP_ANY'),
					'Y' => Loc::getMessage('STOP_WORK_TIME_TRIGGER_PROPERTY_IS_FIRST_STOP_YES'),
					'N' => Loc::getMessage('STOP_WORK_TIME_TRIGGER_PROPERTY_IS_FIRST_STOP_NO'),
				],
				'Required' => false,
				'Multiple' => false,
				'AllowSelection' => false,
				'Default' => '',
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
		if (!class_exists(StopWorktimeTrigger::class))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$context = $this->getRootActivity()->{CBPDocument::PARAM_TRIGGER_EVENT_DATA} ?? [];

		$userId = (int)($context[StopWorktimeTrigger::FIELD_USER_ID] ?? 0);

		if (!empty($userId))
		{
			$this->{self::RETURN_PARAM_USER} = 'user_' . $userId;
		}

		$recordId = (int)($context[StopWorktimeTrigger::FIELD_RECORD_ID] ?? 0);

		if ($recordId > 0)
		{
			$this->{self::RETURN_PARAM_RECORD_ID} = $recordId;
		}

		$this->{self::RETURN_PARAM_IS_FIRST_STOP} =
			($context[StopWorktimeTrigger::FIELD_IS_FIRST_STOP] ?? false) ? 'Y' : 'N'
		;

		return CBPActivityExecutionStatus::Closed;
	}

	public function checkApplyRules(array $rules, TriggerParameters $parameters): Result
	{
		if (!\Bitrix\Main\Loader::includeModule('timeman'))
		{
			return Result::createFromErrorCode(
				Error::MODULE_NOT_INSTALLED
			);
		}

		$userId = (int)$parameters->get(StopWorktimeTrigger::FIELD_USER_ID);

		if (!CBPHelper::isEmptyValue($this->{self::PARAM_USER_IDS}))
		{
			$allowedUserIds = CBPHelper::ExtractUsers($this->{self::PARAM_USER_IDS}, $this->getDocumentId());

			if (!$this->checkUsersUseAbility($allowedUserIds, $userId))
			{
				return Result::createError(
					new Error(Loc::getMessage('STOP_WORK_TIME_TRIGGER_PROPERTY_USER_IDS_INCORRECT')),
				);
			}
		}

		$isFirstStopFilter = (string)$this->{self::PARAM_IS_FIRST_STOP};
		if ($isFirstStopFilter !== '')
		{
			$isFirstStop = \CBPHelper::getBool($parameters->get(StopWorktimeTrigger::FIELD_IS_FIRST_STOP));
			if (\CBPHelper::getBool($isFirstStopFilter) !== $isFirstStop)
			{
				return Result::createError(
					new Error(Loc::getMessage('STOP_WORK_TIME_TRIGGER_PROPERTY_IS_FIRST_STOP_INCORRECT')),
				);
			}
		}

		return Result::createOk();
	}

	private function checkUsersUseAbility(array $userIds, int $userId): bool
	{
		return in_array($userId, $userIds, true);
	}
}
