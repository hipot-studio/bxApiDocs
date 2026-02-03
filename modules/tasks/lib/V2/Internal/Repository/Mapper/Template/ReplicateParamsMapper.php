<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;
use Bitrix\Tasks\Validation\Validator\SerializedValidator;

class ReplicateParamsMapper
{
	public function __construct(
		private readonly PeriodMapper $periodMapper,
	)
	{

	}

	public function mapFromValueObject(ReplicateParams $params): array
	{
		$data = [];

		if ($params->period)
		{
			$data['PERIOD'] = $this->periodMapper->mapFromEnum($params->period);
		}

		if ($params->everyDay !== null)
		{
			$data['EVERY_DAY'] = (int)$params->everyDay;
		}

		if ($params->workdayOnly !== null)
		{
			$data['WORKDAY_ONLY'] = (string)$params->workdayOnly;
		}

		if ($params->dailyMonthInterval !== null)
		{
			$data['DAILY_MONTH_INTERVAL'] = (int)$params->dailyMonthInterval;
		}

		if ($params->everyWeek !== null)
		{
			$data['EVERY_WEEK'] = (int)$params->everyWeek;
		}

		if ($params->monthlyType !== null)
		{
			$data['MONTHLY_TYPE'] = $params->monthlyType->value;
		}
		if ($params->monthlyDayNum !== null)
		{
			$data['MONTHLY_DAY_NUM'] = (int)$params->monthlyDayNum;
		}
		if ($params->monthlyMonthNum1 !== null)
		{
			$data['MONTHLY_MONTH_NUM_1'] = (int)$params->monthlyMonthNum1;
		}
		if ($params->monthlyWeekDayNum !== null)
		{
			$data['MONTHLY_WEEK_DAY_NUM'] = (int)$params->monthlyWeekDayNum;
		}
		if ($params->monthlyWeekDay !== null)
		{
			$data['MONTHLY_WEEK_DAY'] = (int)$params->monthlyWeekDay;
		}
		if ($params->monthlyMonthNum2 !== null)
		{
			$data['MONTHLY_MONTH_NUM_2'] = (int)$params->monthlyMonthNum2;
		}

		if ($params->yearlyType !== null)
		{
			$data['YEARLY_TYPE'] = $params->yearlyType->value;
		}
		if ($params->yearlyDayNum !== null)
		{
			$data['YEARLY_DAY_NUM'] = (int)$params->yearlyDayNum;
		}
		if ($params->yearlyMonth1 !== null)
		{
			$data['YEARLY_MONTH_1'] = (int)$params->yearlyMonth1;
		}
		if ($params->yearlyWeekDayNum !== null)
		{
			$data['YEARLY_WEEK_DAY_NUM'] = (int)$params->yearlyWeekDayNum;
		}
		if ($params->yearlyWeekDay !== null)
		{
			$data['YEARLY_WEEK_DAY'] = (int)$params->yearlyWeekDay;
		}
		if ($params->yearlyMonth2 !== null)
		{
			$data['YEARLY_MONTH_2'] = (int)$params->yearlyMonth2;
		}

		if (is_array($params->weekDays))
		{
			$data['WEEK_DAYS'] = array_values(array_map('intval', $params->weekDays));
		}

		if ($params->time !== null)
		{
			$data['TIME'] = (string)$params->time;
		}

		if ($params->timezoneOffset !== null)
		{
			$data['TIMEZONE_OFFSET'] = (string)$params->timezoneOffset;
		}
		else
		{
			$data['TIMEZONE_OFFSET'] = '0';
		}

		if ($params->repeatTill !== null)
		{
			$data['REPEAT_TILL'] = $params->repeatTill->value;
		}

		if ($params->times !== null)
		{
			$data['TIMES'] = (string)$params->times;
		}

		if ($params->startDate !== null && $params->startDate !== '')
		{
			$data['START_DATE'] = (string)$params->startDate;
		}

		if ($params->endDate !== null && $params->endDate !== '')
		{
			$data['END_DATE'] = (string)$params->endDate;
		}

		if ($params->nextExecutionTime !== null)
		{
			$data['NEXT_EXECUTION_TIME'] = (string)$params->nextExecutionTime;
		}

		if ($params->deadlineOffset !== null)
		{
			$data['DEADLINE_OFFSET'] = (string)$params->deadlineOffset;
		}

		return $data;
	}

	public function mapToValueObject(null|string|array $params): ?ReplicateParams
	{
		if ($params === null)
		{
			return null;
		}

		if (is_string($params))
		{
			$validator = new SerializedValidator();

			if ($validator->validate($params)->isSuccess())
			{
				$params = unserialize($params, ['allowed_classes' => false]);
			}
			else
			{
				return null;
			}
		}

		$entityFields = [];

		if (isset($params['PERIOD']))
		{
			$entityFields['period'] = $this->periodMapper->mapToEnum((string)$params['PERIOD']);
		}

		if (isset($params['EVERY_DAY']))
		{
			$entityFields['everyDay'] = (int)$params['EVERY_DAY'];
		}

		if (isset($params['WORKDAY_ONLY']))
		{
			$entityFields['workdayOnly'] = (string)$params['WORKDAY_ONLY'];
		}

		if (isset($params['DAILY_MONTH_INTERVAL']))
		{
			$entityFields['dailyMonthInterval'] = (int)$params['DAILY_MONTH_INTERVAL'];
		}

		if (isset($params['EVERY_WEEK']))
		{
			$entityFields['everyWeek'] = (int)$params['EVERY_WEEK'];
		}

		// Monthly mappings
		if (isset($params['MONTHLY_TYPE']))
		{
			$entityFields['monthlyType'] = (int)$params['MONTHLY_TYPE'];
		}

		if (isset($params['MONTHLY_DAY_NUM']))
		{
			$entityFields['monthlyDayNum'] = (int)$params['MONTHLY_DAY_NUM'];
		}

		if (isset($params['MONTHLY_MONTH_NUM_1']))
		{
			$entityFields['monthlyMonthNum1'] = (int)$params['MONTHLY_MONTH_NUM_1'];
		}

		if (isset($params['MONTHLY_WEEK_DAY_NUM']))
		{
			$entityFields['monthlyWeekDayNum'] = (int)$params['MONTHLY_WEEK_DAY_NUM'];
		}

		if (isset($params['MONTHLY_WEEK_DAY']))
		{
			$entityFields['monthlyWeekDay'] = (int)$params['MONTHLY_WEEK_DAY'];
		}

		if (isset($params['MONTHLY_MONTH_NUM_2']))
		{
			$entityFields['monthlyMonthNum2'] = (int)$params['MONTHLY_MONTH_NUM_2'];
		}

		// Yearly mappings
		if (isset($params['YEARLY_TYPE']))
		{
			$entityFields['yearlyType'] = (int)$params['YEARLY_TYPE'];
		}

		if (isset($params['YEARLY_DAY_NUM']))
		{
			$entityFields['yearlyDayNum'] = (int)$params['YEARLY_DAY_NUM'];
		}

		if (isset($params['YEARLY_MONTH_1']))
		{
			$entityFields['yearlyMonth1'] = (int)$params['YEARLY_MONTH_1'];
		}

		if (isset($params['YEARLY_WEEK_DAY_NUM']))
		{
			$entityFields['yearlyWeekDayNum'] = (int)$params['YEARLY_WEEK_DAY_NUM'];
		}

		if (isset($params['YEARLY_WEEK_DAY']))
		{
			$entityFields['yearlyWeekDay'] = (int)$params['YEARLY_WEEK_DAY'];
		}

		if (isset($params['YEARLY_MONTH_2']))
		{
			$entityFields['yearlyMonth2'] = (int)$params['YEARLY_MONTH_2'];
		}

		if (isset($params['TIME']))
		{
			$entityFields['time'] = $params['TIME'];
		}

		if (isset($params['TIMEZONE_OFFSET']))
		{
			$entityFields['timezoneOffset'] = (string)$params['TIMEZONE_OFFSET'];
		}

		if (isset($params['REPEAT_TILL']))
		{
			$entityFields['repeatTill'] = (string)$params['REPEAT_TILL'];
		}
		elseif ((string)($params['data']['END_DATE'] ?? '') !== '' && !array_key_exists('REPEAT_TILL', $params['data'] ?? []))
		{
			$entityFields['repeatTill'] = 'date';
		}

		if (isset($params['TIMES']))
		{
			$entityFields['times'] = (string)$params['TIMES'];
		}

		if (isset($params['START_DATE']))
		{
			$entityFields['startDate'] = (string)$params['START_DATE'];
		}

		if (isset($params['END_DATE']))
		{
			$entityFields['endDate'] = (string)$params['END_DATE'];
		}

		if (isset($params['WEEK_DAYS']))
		{
			$entityFields['weekDays'] = array_values(array_map('intval', $params['WEEK_DAYS']));
		}
		else
		{
			$entityFields['weekDays'] = [];
		}

		return ReplicateParams::mapFromArray($entityFields);
	}
}
