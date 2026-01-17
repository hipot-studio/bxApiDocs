<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Template;

use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;

class ReplicateParamsDto extends Dto
{
	public ?string $period = null;
	public ?string $everyDay = null;
	public ?string $workdayOnly = null;
	public ?string $dailyMonthInterval = null;
	public ?string $everyWeek = null;
	public ?string $monthlyType = null;
	public ?string $monthlyDayNum = null;
	public ?string $monthlyMonthNum1 = null;
	public ?string $monthlyWeekDayNum = null;
	public ?string $monthlyWeekDay = null;
	public ?string $monthlyMonthNum2 = null;
	public ?string $yearlyType = null;
	public ?string $yearlyDayNum = null;
	public ?string $yearlyMonth1 = null;
	public ?string $yearlyWeekDayNum = null;
	public ?string $yearlyWeekDay = null;
	public ?string $yearlyMonth2 = null;
	public ?string $time = null;
	public ?string $timezoneOffset = null;
	public ?string $startDate = null;
	public ?string $repeatTill = null;
	public ?string $endDate = null;
	public ?string $times = null;

	public static function fromEntity(?ReplicateParams $params, ?Request $request = null): ?self
	{
		if (!$params)
		{
			return null;
		}
		$select = $request?->select?->getList(true) ?? [];
		$dto = new self();
		if (empty($select) || in_array('period', $select, true))
		{
			$dto->period = $params->period?->value;
		}
		if (empty($select) || in_array('everyDay', $select, true))
		{
			$dto->everyDay = $params->everyDay;
		}
		if (empty($select) || in_array('workdayOnly', $select, true))
		{
			$dto->workdayOnly = $params->workdayOnly;
		}
		if (empty($select) || in_array('dailyMonthInterval', $select, true))
		{
			$dto->dailyMonthInterval = $params->dailyMonthInterval;
		}
		if (empty($select) || in_array('everyWeek', $select, true))
		{
			$dto->everyWeek = $params->everyWeek;
		}
		if (empty($select) || in_array('monthlyType', $select, true))
		{
			$dto->monthlyType = $params->monthlyType?->value;
		}
		if (empty($select) || in_array('monthlyDayNum', $select, true))
		{
			$dto->monthlyDayNum = $params->monthlyDayNum;
		}
		if (empty($select) || in_array('monthlyMonthNum1', $select, true))
		{
			$dto->monthlyMonthNum1 = $params->monthlyMonthNum1;
		}
		if (empty($select) || in_array('monthlyWeekDayNum', $select, true))
		{
			$dto->monthlyWeekDayNum = $params->monthlyWeekDayNum;
		}
		if (empty($select) || in_array('monthlyWeekDay', $select, true))
		{
			$dto->monthlyWeekDay = $params->monthlyWeekDay;
		}
		if (empty($select) || in_array('monthlyMonthNum2', $select, true))
		{
			$dto->monthlyMonthNum2 = $params->monthlyMonthNum2;
		}
		if (empty($select) || in_array('yearlyType', $select, true))
		{
			$dto->yearlyType = $params->yearlyType?->value;
		}
		if (empty($select) || in_array('yearlyDayNum', $select, true))
		{
			$dto->yearlyDayNum = $params->yearlyDayNum;
		}
		if (empty($select) || in_array('yearlyMonth1', $select, true))
		{
			$dto->yearlyMonth1 = $params->yearlyMonth1;
		}
		if (empty($select) || in_array('yearlyWeekDayNum', $select, true))
		{
			$dto->yearlyWeekDayNum = $params->yearlyWeekDayNum;
		}
		if (empty($select) || in_array('yearlyWeekDay', $select, true))
		{
			$dto->yearlyWeekDay = $params->yearlyWeekDay;
		}
		if (empty($select) || in_array('yearlyMonth2', $select, true))
		{
			$dto->yearlyMonth2 = $params->yearlyMonth2;
		}
		if (empty($select) || in_array('time', $select, true))
		{
			$dto->time = $params->time;
		}
		if (empty($select) || in_array('timezoneOffset', $select, true))
		{
			$dto->timezoneOffset = $params->timezoneOffset;
		}
		if (empty($select) || in_array('startDate', $select, true))
		{
			$dto->startDate = $params->startDate;
		}
		if (empty($select) || in_array('repeatTill', $select, true))
		{
			$dto->repeatTill = $params->repeatTill;
		}
		if (empty($select) || in_array('endDate', $select, true))
		{
			$dto->endDate = $params->endDate;
		}
		if (empty($select) || in_array('times', $select, true))
		{
			$dto->times = $params->times;
		}

		return $dto;
	}
}
