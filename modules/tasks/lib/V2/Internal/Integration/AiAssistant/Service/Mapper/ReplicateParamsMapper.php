<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Mapper;

use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\MakeTaskRecurringDto;

class ReplicateParamsMapper
{
	public function convertFromDto(MakeTaskRecurringDto $dto): ReplicateParams
	{
		return new ReplicateParams(
			period: $dto->period,
			everyDay: $dto->everyDay,
			workdayOnly: $dto->workdayOnly ? 'Y' : 'N',
			dailyMonthInterval: $dto->dailyMonthInterval,
			everyWeek: $dto->everyWeek,
			weekDays: $dto->weekDays,
			monthlyType: $dto->monthlyType,
			monthlyDayNum: $dto->monthlyDayNum,
			monthlyMonthNum1: $dto->monthlyMonthNum1,
			monthlyWeekDayNum: $dto->monthlyWeekDayNum,
			monthlyWeekDay: $dto->monthlyWeekDay,
			monthlyMonthNum2: $dto->monthlyMonthNum2,
			yearlyType: $dto->yearlyType,
			yearlyDayNum: $dto->yearlyDayNum,
			yearlyMonth1: $dto->yearlyMonth1,
			yearlyWeekDayNum: $dto->yearlyWeekDayNum,
			yearlyWeekDay: $dto->yearlyWeekDay,
			yearlyMonth2: $dto->yearlyMonth2,
			time: $dto->time,
			startDate: $dto->startDate?->format('Y-m-d H:i:s'),
			repeatTill: $dto->repeatTill,
			endDate: $dto->endDate?->format('Y-m-d H:i:s'),
			times: $dto->times,
		);
	}
}
