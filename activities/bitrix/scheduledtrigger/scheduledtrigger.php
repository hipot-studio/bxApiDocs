<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Result;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPScheduledTrigger extends \Bitrix\Bizproc\Activity\BaseTrigger
{
	public const PROPERTY_SCHEDULE_TYPE = 'ScheduleType';
	public const PROPERTY_RUN_AT = 'RunAt';
	public const PROPERTY_INTERVAL = 'Interval';
	public const PROPERTY_INTERVAL_TEXT = 'Interval_text';
	public const PROPERTY_WEEK_DAYS = 'WeekDays';
	public const PROPERTY_MONTH_DAY = 'MonthDay';
	public const PROPERTY_YEAR_MONTH = 'YearMonth';

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			self::PROPERTY_SCHEDULE_TYPE => \Bitrix\Bizproc\Internal\Service\Trigger\Schedule\ScheduleType::Once->value,
			self::PROPERTY_RUN_AT => null,
			self::PROPERTY_INTERVAL => 1,
			self::PROPERTY_WEEK_DAYS => [],
			self::PROPERTY_MONTH_DAY => null,
			self::PROPERTY_YEAR_MONTH => null,
		];
	}

	public function execute(): int
	{
		return CBPActivityExecutionStatus::Closed;
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors
	): bool
	{
		$errors = [];
		$properties = [];

		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		$map = static::getPropertiesMap($documentType, is_array($currentValues) ? $currentValues : []);

		$map[self::PROPERTY_INTERVAL_TEXT] = [
			'FieldName' => self::PROPERTY_INTERVAL_TEXT,
			'Type' => FieldType::STRING,
		];

		foreach ($map as $id => $property)
		{
			$value = $documentService->getFieldInputValue(
				$documentType,
				$property,
				$property['FieldName'],
				$currentValues,
				$errors,
			);

			if (!empty($errors))
			{
				return false;
			}

			$properties[$id] = $value;
		}

		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		$errors = static::validateProperties($properties, $user);
		if ($errors)
		{
			return false;
		}

		unset($properties[self::PROPERTY_INTERVAL_TEXT]);

		$currentActivity = &\CBPWorkflowTemplateLoader::findActivityByName($workflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PROPERTY_SCHEDULE_TYPE => [
				'Name' => Loc::getMessage('BPSCT_SCHEDULE_TYPE'),
				'FieldName' => self::PROPERTY_SCHEDULE_TYPE,
				'Type' => FieldType::SELECT,
				'Required' => true,
				'Options' => self::getScheduleTypes(),
			],
			self::PROPERTY_RUN_AT => [
				'Name' => Loc::getMessage('BPSCT_RUN_AT'),
				'FieldName' => self::PROPERTY_RUN_AT,
				'Type' => FieldType::DATETIME,
				'Required' => true,
				'Settings' => [
					'defaultDate' => (new \Bitrix\Main\Type\DateTime())
						->disableUserTime()
						->setTime(0, 0)
						->toString(),
				],
			],
			self::PROPERTY_INTERVAL => [
				'Name' => Loc::getMessage('BPSCT_INTERVAL'),
				'FieldName' => self::PROPERTY_INTERVAL,
				'Type' => FieldType::SELECT,
				'Options' => self::getIntervalOptions(),
			],
			self::PROPERTY_WEEK_DAYS => [
				'Name' => Loc::getMessage('BPSCT_WEEK_DAYS'),
				'FieldName' => self::PROPERTY_WEEK_DAYS,
				'Type' => FieldType::SELECT,
				'Multiple' => true,
				'Options' => \Bitrix\Bizproc\Calc\Libs\DateLib::getWeekDayFullNames(),
			],
			self::PROPERTY_YEAR_MONTH => [
				'Name' => Loc::getMessage('BPSCT_YEAR_MONTH'),
				'FieldName' => self::PROPERTY_YEAR_MONTH,
				'Type' => FieldType::SELECT,
				'Options' => \Bitrix\Bizproc\Calc\Libs\DateLib::getMonthFullNames(),
			],
			self::PROPERTY_MONTH_DAY => [
				'Name' => Loc::getMessage('BPSCT_MONTH_DAY'),
				'FieldName' => self::PROPERTY_MONTH_DAY,
				'Type' => FieldType::INT,
			],
		];
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];

		$scheduleType = $arTestProperties[self::PROPERTY_SCHEDULE_TYPE] ?? null;

		if (!$scheduleType)
		{
			$errors[] = [
				'code' => self::PROPERTY_SCHEDULE_TYPE,
				'message' => Loc::getMessage('BPSCT_SCHEDULE_TYPE_EMPTY'),
			];
		}

		$runAt = $arTestProperties[self::PROPERTY_RUN_AT] ?? null;
		if (!$runAt)
		{
			$errors[] = [
				'code' => self::PROPERTY_RUN_AT,
				'message' => Loc::getMessage('BPSCT_RUN_AT_EMPTY'),
			];
		}

		$interval = $arTestProperties[self::PROPERTY_INTERVAL] ?? null;
		$intervalText = $arTestProperties[self::PROPERTY_INTERVAL_TEXT] ?? null;

		if (empty($interval) && empty($intervalText) || is_numeric($interval) && ($interval < 1 || $interval > 12))
		{
			$errors[] = [
				'code' => self::PROPERTY_INTERVAL,
				'message' => Loc::getMessage('BPSCT_INTERVAL_INVALID'),
			];
		}

		return array_merge($errors, parent::validateProperties($arTestProperties, $user));
	}

	public function createApplyRules(): array
	{
		$rules = parent::createApplyRules();
		$rules['TriggerName'] = $this->getName();

		return $rules;
	}

	public function checkApplyRules(array $rules, TriggerParameters $parameters): Result
	{
		$expectedTriggerName = $rules['TriggerName'] ?? null;
		$triggerName = $parameters->get('triggerName');

		if ($expectedTriggerName !== $triggerName)
		{
			return Result::createError(new Error('scheduled trigger mismatch'));
		}

		return Result::createOk();
	}

	/**
	 * @return array
	 */
	protected static function getScheduleTypes(): array
	{
		return [
			\Bitrix\Bizproc\Internal\Service\Trigger\Schedule\ScheduleType::Hourly->value =>
				Loc::getMessage('BPSCT_SCHEDULE_TYPE_HOURLY'),
			\Bitrix\Bizproc\Internal\Service\Trigger\Schedule\ScheduleType::Daily->value =>
				Loc::getMessage('BPSCT_SCHEDULE_TYPE_DAILY'),
			\Bitrix\Bizproc\Internal\Service\Trigger\Schedule\ScheduleType::Weekly->value =>
				Loc::getMessage('BPSCT_SCHEDULE_TYPE_WEEKLY'),
			\Bitrix\Bizproc\Internal\Service\Trigger\Schedule\ScheduleType::Monthly->value =>
				Loc::getMessage('BPSCT_SCHEDULE_TYPE_MONTHLY'),
			\Bitrix\Bizproc\Internal\Service\Trigger\Schedule\ScheduleType::Yearly->value =>
				Loc::getMessage('BPSCT_SCHEDULE_TYPE_YEARLY'),
		];
	}

	private static function getIntervalOptions(): array
	{
		$options = [];
		for ($i = 1; $i <= 12; $i++)
		{
			$options[$i] = $i;
		}

		return $options;
	}
}
