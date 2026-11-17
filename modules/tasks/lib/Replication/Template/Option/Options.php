<?php

namespace Bitrix\Tasks\Replication\Template\Option;

use Bitrix\Tasks\Replication\Template\Enum\DeadlineType;
use Bitrix\Tasks\Replication\Template\Time\Enum\RepeatType;
use Bitrix\Tasks\UI;

class Options
{
	/** @see ExecutionTimeFactory::getTemplateDailyExecutionDate() */
	public const DAYS_IN_MONTH = 30;

	public static array $allowedFields = [
		'PERIOD' => true,
		'EVERY_DAY' => true,
		'WORKDAY_ONLY' => true,
		'EVERY_WEEK' => true,
		'WEEK_DAYS' => true,
		'MONTHLY_TYPE' => true,
		'MONTHLY_DAY_NUM' => true,
		'MONTHLY_MONTH_NUM_1' => true,
		'MONTHLY_WEEK_DAY_NUM' => true,
		'MONTHLY_WEEK_DAY' => true,
		'MONTHLY_MONTH_NUM_2' => true,
		'YEARLY_TYPE' => true,
		'YEARLY_DAY_NUM' => true,
		'YEARLY_MONTH_1' => true,
		'YEARLY_WEEK_DAY_NUM' => true,
		'YEARLY_WEEK_DAY' => true,
		'YEARLY_MONTH_2' => true,
		'START_DATE' => true,
		'END_DATE' => true,
		'TIME' => true,
		// deprecated, TIMEZONE_OFFSET parameter is always 0 in new templates
		'TIMEZONE_OFFSET' => true,
		// deprecated DAILY_MONTH_INTERVAL, daily interval now stores in EVERY_DAY
		'DAILY_MONTH_INTERVAL' => true,
		'REPEAT_TILL' => true,
		'TIMES' => true,
		'NEXT_EXECUTION_TIME' => true,
		'DEADLINE_OFFSET' => true,
		'DEADLINE_TYPE' => true,
		'CREATE_OFFSET' => true,
		'COMPLETION_DELAY' => true,
		'OFFSET_DAY_TYPE' => true,
	];

	public static function validate(array $options): array
	{
		foreach ($options as $field => $value)
		{
			if (!isset(static::$allowedFields[$field]))
			{
				unset($options[$field]);
			}
		}

		$options['EVERY_DAY'] = ((int)($options['EVERY_DAY'] ?? null) ?: 1);
		$options['EVERY_WEEK'] = ((int)($options['EVERY_WEEK'] ?? null) ?: 1);
		$options['MONTHLY_DAY_NUM'] = ((int)($options['MONTHLY_DAY_NUM'] ?? null) ?: 1);
		$options['MONTHLY_MONTH_NUM_1'] = ((int)($options['MONTHLY_MONTH_NUM_1'] ?? null) ?: 1);
		$options['MONTHLY_MONTH_NUM_2'] = ((int)($options['MONTHLY_MONTH_NUM_2'] ?? null) ?: 1);
		$options['YEARLY_DAY_NUM'] = ((int)($options['YEARLY_DAY_NUM'] ?? null) ?: 1);

		$options['PERIOD'] = (string)($options['PERIOD'] ?? null);
		$options['WEEK_DAYS'] = ($options['WEEK_DAYS'] ?? null);
		$options['TIME'] = ($options['TIME'] ?? '');
		$options['WORKDAY_ONLY'] = (($options['WORKDAY_ONLY'] ?? null) === 'Y' ? 'Y' : 'N');
		$options['END_DATE'] = ($options['END_DATE'] ?? null);

		$options['MONTHLY_TYPE'] = static::validateTypeSelector($options['MONTHLY_TYPE'] ?? 0);
		$options['YEARLY_TYPE'] = static::validateTypeSelector($options['YEARLY_TYPE'] ?? 0);

		if ($options['PERIOD'] === '')
		{
			$options['PERIOD'] = 'daily';
		}
		if (!is_array($options['WEEK_DAYS']))
		{
			$options['WEEK_DAYS'] = [];
		}

		$time = 3600 * 5; // five hours
		if (trim($options['TIME']) !== '')
		{
			$time = UI::parseTimeAmount($options['TIME'], 'HH:MI');
		}
		$options['TIME'] = UI::formatTimeAmount($time, 'HH:MI');

		// deprecated, TIMEZONE_OFFSET parameter is always 0 in new templates
		if (array_key_exists('TIMEZONE_OFFSET', $options))
		{
			$options['TIMEZONE_OFFSET'] = (int)$options['TIMEZONE_OFFSET'];
		}

		// old templates may have no usable REPEAT_TILL: a stored END_DATE means 'date', anything else is endless
		if (!in_array($options['REPEAT_TILL'] ?? null, RepeatType::getAll(), true))
		{
			$options['REPEAT_TILL'] = ((string)$options['END_DATE'] !== '' ? RepeatType::DATE : RepeatType::ENDLESS);
		}

		$options['DEADLINE_AFTER'] = (int)($options['DEADLINE_AFTER'] ?? null);

		// A zero or absent offset means "no deadline" unless DEADLINE_TYPE says "on the creation day".
		// Both null and a missing key are stored the same way - as no key at all.
		if (isset($options['DEADLINE_OFFSET']))
		{
			$options['DEADLINE_OFFSET'] = (int)$options['DEADLINE_OFFSET'];
		}
		else
		{
			unset($options['DEADLINE_OFFSET']);
		}

		// a positive offset wins over the type
		if (
			($options['DEADLINE_TYPE'] ?? null) !== DeadlineType::CreationDay->value
			|| ($options['DEADLINE_OFFSET'] ?? 0) > 0
		)
		{
			unset($options['DEADLINE_TYPE']);
		}

		// Regularity type params (deadline / after_completion): coerce to their stored types when present,
		// so consumers no longer have to cast at read time. OFFSET_DAY_TYPE is a string enum, not int.
		if (array_key_exists('CREATE_OFFSET', $options))
		{
			$options['CREATE_OFFSET'] = (int)$options['CREATE_OFFSET'];
		}
		if (array_key_exists('COMPLETION_DELAY', $options))
		{
			$options['COMPLETION_DELAY'] = (int)$options['COMPLETION_DELAY'];
		}
		if (array_key_exists('OFFSET_DAY_TYPE', $options))
		{
			$options['OFFSET_DAY_TYPE'] = (string)$options['OFFSET_DAY_TYPE'];
		}

		/*
		 * For extreme cases when the stepper ReplicateParamsConverter.php could suddenly break during execution
		 *
		 * deprecated DAILY_MONTH_INTERVAL, daily interval now stores in EVERY_DAY
		*/
		if (!empty($options['DAILY_MONTH_INTERVAL']))
		{
			$options['EVERY_DAY'] += $options['DAILY_MONTH_INTERVAL'] * self::DAYS_IN_MONTH;
			$options['DAILY_MONTH_INTERVAL'] = 0;
		}

		return $options;
	}

	public static function isNewAndCurrentOptionsEquals(array $newOptions, array $currentOptions): bool
	{
		$newOptions = static::validate($newOptions);
		$currentOptions = static::validate($currentOptions);

		foreach ($newOptions as $key => $value)
		{
			$secondValue = $currentOptions[$key] ?? null;
			if ($secondValue != $value)
			{
				return false;
			}
		}

		return true;
	}

	private static function validateTypeSelector(int $type): int
	{
		if ($type < 1 || $type > 2)
		{
			$type = 1;
		}

		return $type;
	}
}
