<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Trigger\Schedule;

use Bitrix\Bizproc\Internal\Entity\Trigger\ScheduledData;
use Bitrix\Main\Type\DateTime;
use Recurr\Exception\InvalidArgument;
use Recurr\Exception\InvalidRRule;
use Recurr\Exception\InvalidWeekday;
use Recurr\Frequency;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use Recurr\Transformer\Constraint\AfterConstraint;

class ScheduleCalculator
{
	private const MAX_INTERVAL = 12;
	private const MAX_FREQUENCIES = 200;

	/**
	 * @throws InvalidRRule
	 * @throws InvalidWeekday
	 * @throws InvalidArgument
	 * @throws \Exception
	 */
	public function calculateNextRunAt(array $scheduleData, ?DateTime $now = null): ?DateTime
	{
		$now ??= new DateTime();
		$data = ScheduledData::fromArray($scheduleData);

		if ($data->interval < 1 || $data->interval > self::MAX_INTERVAL)
		{
			return null;
		}

		$timezone = $this->resolveTimeZone($data->timezone);
		$nowLocal = $this->toLocalTime($now, $timezone);
		$startLocal = $this->parseLocalDateTime($data->startAt, $timezone);

		if ($startLocal === null)
		{
			return null;
		}

		if ($data->frequency === ScheduleType::Once)
		{
			return $startLocal > $nowLocal ? $this->toUtcDateTime($startLocal) : null;
		}

		$rule = $this->buildRule($data, $startLocal, $timezone);
		if ($rule === null)
		{
			return null;
		}

		$config = new ArrayTransformerConfig();
		$config->setVirtualLimit(self::MAX_FREQUENCIES);
		$config->enableLastDayOfMonthFix();
		$transformer = new ArrayTransformer($config);
		$constraint = new AfterConstraint($nowLocal, false);

		$recurrences = $transformer->transform($rule, $constraint);
		if (!$recurrences || !isset($recurrences[0]))
		{
			return null;
		}

		$next = $recurrences[0]->getStart();
		if (!$next instanceof \DateTimeInterface)
		{
			return null;
		}

		return $this->toUtcDateTime(\DateTimeImmutable::createFromInterface($next));
	}

	/**
	 * @throws InvalidRRule
	 * @throws InvalidArgument
	 */
	private function buildRule(
		ScheduledData $data,
		\DateTimeImmutable $startLocal,
		\DateTimeZone $timezone,
	): ?Rule
	{
		$rule = new Rule(null, $startLocal, null, $timezone->getName());
		$rule->setFreq($this->mapFrequency($data->frequency));
		$rule->setInterval($data->interval);

		if (!empty($data->byWeekDay))
		{
			$rule->setByDay($data->byWeekDay);
		}

		if (!empty($data->byMonthDay))
		{
			$rule->setByMonthDay([$data->byMonthDay]);
		}

		if (!empty($data->byMonth))
		{
			$rule->setByMonth([$data->byMonth]);
		}

		return $rule;
	}

	private function mapFrequency(ScheduleType $frequency): int
	{
		return match ($frequency)
		{
			ScheduleType::Hourly => Frequency::HOURLY,
			ScheduleType::Weekly => Frequency::WEEKLY,
			ScheduleType::Monthly => Frequency::MONTHLY,
			ScheduleType::Yearly => Frequency::YEARLY,
			default => Frequency::DAILY,
		};
	}

	private function resolveTimeZone(string $timezone): \DateTimeZone
	{
		try
		{
			return new \DateTimeZone($timezone);
		}
		catch (\Exception)
		{
			return new \DateTimeZone('UTC');
		}
	}

	/**
	 * @throws \Exception
	 */
	private function toLocalTime(DateTime $now, \DateTimeZone $timezone): \DateTimeImmutable
	{
		return (new \DateTimeImmutable('@' . $now->getTimestamp()))->setTimezone($timezone);
	}

	private function parseLocalDateTime(string $dateTime, \DateTimeZone $timezone): ?\DateTimeImmutable
	{
		try
		{
			$timestamp = (new \DateTimeImmutable($dateTime, $timezone))->getTimestamp();

			return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
		}
		catch (\Exception)
		{
			return null;
		}
	}

	private function toUtcDateTime(\DateTimeImmutable $local): DateTime
	{
		$utc = $local->setTimezone(new \DateTimeZone('UTC'));
		$result = DateTime::createFromTimestamp($utc->getTimestamp());
		$result->setTimeZone(new \DateTimeZone('UTC'));

		return $result;
	}
}
