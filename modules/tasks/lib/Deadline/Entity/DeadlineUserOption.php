<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Entity;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\Max;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Tasks\Deadline\Configuration;
use Bitrix\Tasks\Deadline\SkipNotificationPeriod;
use Bitrix\Tasks\Integration\Calendar\Calendar;
use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class DeadlineUserOption extends AbstractEntity
{
	use MapTypeTrait;

	private const SECONDS_IN_DAY = 60 * 60 * 24;
	private const SECONDS_IN_WEEK = self::SECONDS_IN_DAY * 7;
	private const SECONDS_IN_MONTH = self::SECONDS_IN_DAY * 31;

	public ?int $id = null;

	public function __construct(
		#[Min(0)]
		public int $userId,
		#[Min(0)]
		#[Max(Configuration::MAX_DEFAULT_DEADLINE_IN_SECONDS)]
		public int $defaultDeadlineInSeconds = self::SECONDS_IN_DAY * 5,
		public bool $isExactDeadlineTime = false,
		public SkipNotificationPeriod $skipNotificationPeriod = SkipNotificationPeriod::DEFAULT,
		public ?DateTime $skipNotificationStartDate = null,
		public bool $canChangeDeadline = false,
		public ?DateTime $maxDeadlineChangeDate = null,
		public ?int $maxDeadlineChanges = null,
		public bool $requireDeadlineChangeReason = false,
		public bool $matchWorkTime = true,
	)
	{
	}

	public function getDefaultDeadlineDate(
		bool $matchWorkTime = false,
		DateTime $dateTime = new DateTime(),
	): ?DateTime
	{
		if ($this->defaultDeadlineInSeconds === 0)
		{
			return null;
		}

		$calendar = Calendar::createFromPortalSchedule();
		$schedule = $calendar->getSchedule();

		$date = $dateTime->toUserTime();

		$closestDate = $calendar->getClosestDate(
			userDateTime: $date,
			offsetInSeconds: $this->defaultDeadlineInSeconds,
			matchSchedule: $this->isExactDeadlineTime && $matchWorkTime,
			matchWorkTime: $matchWorkTime,
		);

		return ($this->isExactDeadlineTime) ? $closestDate : $schedule->getShiftEnd($closestDate);
	}

	public function getNotificationSkipPeriod(): SkipNotificationPeriod
	{
		if (!$this->isNotificationSkipped())
		{
			return SkipNotificationPeriod::DEFAULT;
		}

		return $this->skipNotificationPeriod;
	}

	public function isNotificationSkipped(): bool
	{
		if ($this->skipNotificationPeriod === SkipNotificationPeriod::DEFAULT)
		{
			return false;
		}
		if ($this->skipNotificationPeriod === SkipNotificationPeriod::FOREVER)
		{
			return true;
		}

		$startDate = $this->skipNotificationStartDate;
		if ($startDate === null)
		{
			return false;
		}

		$dateTime = new DateTime();

		$secondsPassed = $dateTime->getTimestamp() - $startDate->getTimestamp();

		return match ($this->skipNotificationPeriod)
		{
			SkipNotificationPeriod::DAY =>
				$startDate->format('j') === $dateTime->format('j')
				&& $secondsPassed < self::SECONDS_IN_DAY,

			SkipNotificationPeriod::WEEK =>
				$startDate->format('W') === $dateTime->format('W')
				&& $secondsPassed < self::SECONDS_IN_WEEK,

			SkipNotificationPeriod::MONTH =>
				$startDate->format('n') === $dateTime->format('n')
				&& $secondsPassed < self::SECONDS_IN_MONTH,

			default => false,
		};
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'defaultDeadlineInSeconds' => $this->defaultDeadlineInSeconds,
			'isExactDeadlineTime' => $this->isExactDeadlineTime,
			'skipNotificationPeriod' => $this->skipNotificationPeriod->value,
			'skipNotificationStartDate' => $this->skipNotificationStartDate,
			'defaultDeadlineDate' => $this->getDefaultDeadlineDate($this->matchWorkTime)
				?->format('Y-m-d H:i'),
			'canChangeDeadline' => $this->canChangeDeadline,
			'maxDeadlineChangeDate' => $this->maxDeadlineChangeDate?->format(DateTime::getFormat()),
			'maxDeadlineChanges' => $this->maxDeadlineChanges,
			'requireDeadlineChangeReason' => $this->requireDeadlineChangeReason,
		];
	}

	public static function mapFromArray(array $props): static
	{
		if (isset($props['skipNotificationStartDate']) && is_string($props['skipNotificationStartDate']))
		{
			try
			{
				$props['skipNotificationStartDate'] = new DateTime($props['skipNotificationStartDate']);
			}
			catch (\Exception) {}
		}

		if (isset($props['maxDeadlineChangeDate']) && is_string($props['maxDeadlineChangeDate']))
		{
			try
			{
				$props['maxDeadlineChangeDate'] = new DateTime($props['maxDeadlineChangeDate']);
			}
			catch (\Exception) {}
		}

		$deadlineUserOption = new self(
			(int)($props['userId'] ?? 0),
			(int)($props['defaultDeadlineInSeconds'] ?? self::SECONDS_IN_DAY * 5),
			(bool)($props['isExactDeadlineTime'] ?? false),
			SkipNotificationPeriod::tryFrom($props['skipNotificationPeriod'] ?? ''),
			static::mapDateTime($props, 'skipNotificationStartDate'),
			(bool)($props['canChangeDeadline'] ?? false),
			static::mapDateTime($props, 'maxDeadlineChangeDate'),
			isset($props['maxDeadlineChanges']) ? (int)$props['maxDeadlineChanges'] : null,
			(bool)($props['requireDeadlineChangeReason'] ?? false),
		);

		if (isset($props['id']))
		{
			$deadlineUserOption->id = (int)$props['id'];
		}

		return $deadlineUserOption;
	}

	public function getId(): ?int
	{
		return $this->id;
	}
}
