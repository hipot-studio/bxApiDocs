<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Mapper;

use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\MakeTaskRecurringDto;
use Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence\RecurrenceTimeZoneConverter;

class ReplicateParamsMapper
{
	public function __construct(
		private readonly RecurrenceTimeZoneConverter $timeZoneConverter,
	)
	{
	}

	public function convertFromDto(MakeTaskRecurringDto $dto, int $userId): ReplicateParams
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
			time: $this->timeZoneConverter->convertUserTimeToServer($dto->time, $userId, $dto->startDate),
			startDate: $dto->startDate?->format('Y-m-d H:i:s'),
			repeatTill: $dto->repeatTill,
			endDate: $dto->endDate?->format('Y-m-d H:i:s'),
			times: $dto->times,
			deadlineOffset: $dto->deadlineOffset,
			createOffset: $dto->createOffset,
			completionDelay: $dto->completionDelay,
			deadlineType: $dto->deadlineType?->value,
		);
	}
}
