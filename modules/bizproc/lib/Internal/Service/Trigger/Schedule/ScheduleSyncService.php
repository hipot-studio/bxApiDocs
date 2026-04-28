<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Trigger\Schedule;

use Bitrix\Bizproc\BaseType\Value;
use Bitrix\Bizproc\Internal\Entity\Trigger\ScheduledData;
use Bitrix\Bizproc\Internal\Entity\Trigger\TriggerSchedule;
use Bitrix\Bizproc\Internal\Entity\Trigger\TriggerScheduleCollection;
use Bitrix\Bizproc\Internal\Repository\TriggerScheduleRepository\TriggerScheduleRepository;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\SystemException;
use Recurr\Exception\InvalidArgument;
use Recurr\Exception\InvalidRRule;
use Recurr\Exception\InvalidWeekday;

class ScheduleSyncService
{
	public const TRIGGER_TYPE = 'ScheduledTrigger';
	private const TRIGGER_NAME_COLUMN = 'triggerName';
	private const PROPERTIES_COLUMN = 'properties';

	public function __construct(
		private readonly ScheduleCalculator $calculator,
		private readonly TriggerScheduleRepository $repository,
		private readonly Searcher $searcher,
		private readonly ScheduledTriggerAgentSyncService $agentSyncService,
	)
	{
	}

	/**
	 * @param int $templateId
	 * @param array|null $triggers
	 * @param bool $active
	 *
	 * @throws ArgumentException
	 * @throws InvalidArgument
	 * @throws InvalidRRule
	 * @throws InvalidWeekday
	 * @throws ObjectPropertyException
	 * @throws PersistenceException
	 * @throws SystemException
	 * @throws \CBPArgumentOutOfRangeException
	 */
	public function syncByTemplate(int $templateId, ?array $triggers, ?bool $active): void
	{
		if (!$this->searcher->includeActivityFile(strtolower(self::TRIGGER_TYPE)))
		{
			return;
		}

		if (!$active || empty($triggers))
		{
			$this->repository->deleteByTemplate($templateId);
			$this->agentSyncService->syncAgentSchedule();

			return;
		}

		$templateConstants = $this->getTemplateConstants($templateId);
		$scheduleTriggers = $this->filterScheduleTriggers($triggers);
		$triggerNames = array_column($scheduleTriggers, self::TRIGGER_NAME_COLUMN);

		$existingSchedules = $this->indexSchedulesByTriggerName(
			$this->repository->getByTemplate($templateId)
		);
		$this->repository->deleteByTemplate($templateId, $triggerNames);

		foreach ($scheduleTriggers as $trigger)
		{
			$scheduleData = $this->buildScheduleData($trigger[self::PROPERTIES_COLUMN], $templateConstants);
			if ($scheduleData === null)
			{
				continue;
			}

			$triggerName = $trigger[self::TRIGGER_NAME_COLUMN];
			$nextRunAt = $this->calculator->calculateNextRunAt($scheduleData->toArray());
			$entity = (new TriggerSchedule())
				->setTemplateId($templateId)
				->setTriggerName($triggerName)
				->setScheduleType($scheduleData->frequency->value)
				->setScheduleData($scheduleData)
				->setNextRunAt($nextRunAt)
			;

			if (isset($existingSchedules[$triggerName]))
			{
				$existing = $existingSchedules[$triggerName];
				$entity
					->setId($existing->getId())
					->setLastRunAt($existing->getLastRunAt())
				;
			}

			$this->repository->save($entity);
		}

		$this->agentSyncService->syncAgentSchedule();
	}

	private function buildScheduleData(array $properties, array $templateConstants = []): ?ScheduledData
	{
		if ($templateConstants)
		{
			$properties = $this->resolveProperties($properties, $templateConstants);
		}

		$type = ScheduleType::tryFrom((string)($properties[\CBPScheduledTrigger::PROPERTY_SCHEDULE_TYPE] ?? ''));
		if ($type === null)
		{
			return null;
		}

		$runAt = $this->extractRunAtValue($properties[\CBPScheduledTrigger::PROPERTY_RUN_AT] ?? null);
		if ($runAt === null)
		{
			return null;
		}

		$timezone = $this->formatOffsetAsTimeZone($runAt->getOffset());
		$startAt = $this->formatStartAt($runAt, $timezone);

		if ($startAt === null)
		{
			return null;
		}

		$interval = $this->normalizeInterval($properties[\CBPScheduledTrigger::PROPERTY_INTERVAL] ?? 1);
		$byWeekDay = $this->normalizeWeekDays($properties[\CBPScheduledTrigger::PROPERTY_WEEK_DAYS] ?? []);
		$byMonthDay = !empty($properties[\CBPScheduledTrigger::PROPERTY_MONTH_DAY]) ? (int)$properties[\CBPScheduledTrigger::PROPERTY_MONTH_DAY]
			: null;

		$byMonth = !empty($properties[\CBPScheduledTrigger::PROPERTY_YEAR_MONTH]) ? (int)$properties[\CBPScheduledTrigger::PROPERTY_YEAR_MONTH]
			: null;

		return new ScheduledData(
			startAt: $startAt,
			timezone: $timezone,
			frequency: $type,
			interval: $interval,
			byMonth: $byMonth,
			byMonthDay: $byMonthDay,
			byWeekDay: $byWeekDay,
		);
	}

	private function extractRunAtValue(null|Value\Time|Value\DateTime|string $value): ?Value\DateTime
	{
		if ($value instanceof Value\DateTime)
		{
			return $value;
		}

		if (is_string($value) && $value !== '')
		{
			$dateTime = new Value\DateTime($value);

			if ($dateTime->getTimestamp() !== null)
			{
				return $dateTime;
			}

			$time = new Value\Time($value);

			return new Value\DateTime($time->getTimestamp(), $time->getOffset());
		}

		return null;
	}

	private function resolveProperties(array $properties, array $templateConstants): array
	{
		return array_map(
			fn (mixed $value) => $this->resolveValue($value, $templateConstants),
			$properties
		);
	}

	private function resolveValue(mixed $value, array $templateConstants): mixed
	{
		if (is_array($value))
		{
			return array_map(
				fn (mixed $item) => $this->resolveValue($item, $templateConstants),
				$value
			);
		}

		if (!is_string($value))
		{
			return $value;
		}

		$expression = \CBPActivity::parseExpression($value);
		if (!$expression)
		{
			return $value;
		}

		if ($expression['object'] !== 'Constant')
		{
			return null;
		}

		$constantId = $expression['field'] ?? null;
		if (!$constantId || !isset($templateConstants[$constantId]))
		{
			return null;
		}

		return $templateConstants[$constantId]['Default'] ?? null;
	}

	/**
	 * @throws \CBPArgumentOutOfRangeException
	 */
	private function getTemplateConstants(int $templateId): array
	{
		if ($templateId <= 0)
		{
			return [];
		}

		$constants = \CBPWorkflowTemplateLoader::getTemplateConstants($templateId);

		return is_array($constants) ? $constants : [];
	}

	private function formatOffsetAsTimeZone(int $offset): string
	{
		$sign = $offset >= 0 ? '+' : '-';
		$abs = abs($offset);
		$hours = (int)floor($abs / 3600);
		$minutes = (int)floor(($abs % 3600) / 60);

		return sprintf('%s%02d:%02d', $sign, $hours, $minutes);
	}

	private function formatStartAt(Value\DateTime $runAt, string $timezone): ?string
	{
		try
		{
			$tz = new \DateTimeZone($timezone);
			$userLocal = (new \DateTimeImmutable('@' . $runAt->getTimestamp()))->setTimezone($tz);

			$now = new \DateTimeImmutable('now', $tz);
			$local = $now->setTime((int)$userLocal->format('H'), (int)$userLocal->format('i'));

			return $local->format('Y-m-d H:i:s');
		}
		catch (\Exception)
		{
			return null;
		}
	}

	private function normalizeWeekDays(mixed $value): array
	{
		$raw = \CBPHelper::flatten($value ?? []);

		$result = [];
		foreach ($raw as $day)
		{
			$weekday = RecurrWeekday::fromNumericDay((int)$day);
			if ($weekday !== null)
			{
				$result[] = $weekday->value;
			}
		}

		return array_values(array_unique($result));
	}

	private function normalizeInterval(mixed $value): int
	{
		$interval = (int)$value;
		if ($interval < 1)
		{
			return 1;
		}
		if ($interval > 12)
		{
			return 12;
		}

		return $interval;
	}

	private function filterScheduleTriggers(array $triggers): array
	{
		$result = [];
		foreach ($triggers as $trigger)
		{
			if (($trigger['TRIGGER_TYPE'] ?? '') !== self::TRIGGER_TYPE)
			{
				continue;
			}

			$applyRules = $trigger['APPLY_RULES'] ?? [];
			$properties = $applyRules['Properties'] ?? [];
			$triggerName = (string)($applyRules['TriggerName'] ?? $trigger['TRIGGER_NAME'] ?? '');
			if ($triggerName === '')
			{
				continue;
			}

			$result[] = [
				self::TRIGGER_NAME_COLUMN => $triggerName,
				self::PROPERTIES_COLUMN => $properties,
			];
		}

		return $result;
	}

	/**
	 * @param TriggerScheduleCollection $schedules
	 *
	 * @return array<string, TriggerSchedule>
	 */
	private function indexSchedulesByTriggerName(TriggerScheduleCollection $schedules): array
	{
		$indexed = [];
		foreach ($schedules as $schedule)
		{
			$triggerName = $schedule->getTriggerName();
			if ($triggerName !== '')
			{
				$indexed[$triggerName] = $schedule;
			}
		}

		return $indexed;
	}
}
