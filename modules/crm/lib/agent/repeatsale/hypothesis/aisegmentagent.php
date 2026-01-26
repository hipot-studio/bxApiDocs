<?php

namespace Bitrix\Crm\Agent\RepeatSale\Hypothesis;

use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Crm\RepeatSale\Hypothesis\AiSegment;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use CAgent;
use CCrmOwnerType;

final class AiSegmentAgent extends AgentBase
{
	public const AGENT_DONE = false;
	public const PERIODICAL_AGENT_RUN_LATER = true;

	private const MODULE_NAME = 'crm';
	private const LAST_ITEM_ID_OPTION_NAME = 'rs_hypothesis_ai_segment_agent_last_item_id';
	private const COUNT_OPTION_NAME = 'rs_hypothesis_ai_segment_agent_count';
	private const DATE_OPTION_NAME = 'rs_hypothesis_ai_segment_date';
	private const TOTAL_COUNT_OPTION_NAME = 'rs_hypothesis_ai_segment_total_count';

	private const DATES_LIST = [
		'2024-09-01',
		'2024-09-02',
		'2024-09-03',
		'2024-09-04',
		'2024-09-05',
		'2024-12-01',
		'2024-12-02',
		'2024-12-03',
		'2024-12-04',
		'2024-12-05',
		'2025-03-06',
		'2025-03-07',
		'2025-03-08',
		'2025-03-09',
		'2025-03-10',
		'2025-06-01',
		'2025-06-02',
		'2025-06-03',
		'2025-06-04',
		'2025-06-05',
	];

	public static function doRun(): bool
	{
		return (new self())->execute();
	}

	private function execute(): bool
	{
		$date = $this->getDate();
		$data = AiSegment::getInstance()
			->setDate(new Date($date, 'Y-m-d'))
			->execute(CCrmOwnerType::Deal, $this->getNextItemId())
		;
		$count = $data['count'] ?? 0;
		$nextItemId = $data['nextItemId'] ?? null;

		$this->incrementAndSaveCount($count);

		if ($nextItemId === null)
		{
			$this->saveTotalCount($date);

			if ($this->isLastDate($date))
			{
				$this->addCleanAgent();

				return self::AGENT_DONE;
			}

			Option::delete(self::MODULE_NAME, ['name' => self::COUNT_OPTION_NAME]);
			Option::delete(self::MODULE_NAME, ['name' => self::LAST_ITEM_ID_OPTION_NAME]);

			return $this->shiftDate($date);
		}

		$this->saveNextItemId($nextItemId);

		return self::PERIODICAL_AGENT_RUN_LATER;
	}

	private function getDate(): string
	{
		$dates = self::DATES_LIST;

		return Option::get(self::MODULE_NAME, self::DATE_OPTION_NAME, array_shift($dates));
	}

	private function isLastDate(string $date): bool
	{
		$dates = self::DATES_LIST;

		return end($dates) === $date;
	}

	private function shiftDate(string $currentDate): bool
	{
		$dates = self::DATES_LIST;
		$currentIndex = array_search($currentDate, $dates, true);
		if ($currentIndex === false)
		{
			return false;
		}

		$nextIndex = $currentIndex + 1;
		if (!isset($dates[$nextIndex]))
		{
			return false;
		}

		$nextDate = $dates[$nextIndex];
		Option::set(self::MODULE_NAME, self::DATE_OPTION_NAME, $nextDate);

		return true;
	}

	private function saveNextItemId(int $nextItemId): void
	{
		Option::set(self::MODULE_NAME, self::LAST_ITEM_ID_OPTION_NAME, $nextItemId);
	}

	private function incrementAndSaveCount(int $amount): void
	{
		$currentCount = $this->getCount();

		if ($amount <= 0 && $currentCount !== null)
		{
			return;
		}

		$value = $currentCount + $amount;
		Option::set(self::MODULE_NAME, self::COUNT_OPTION_NAME, $value);
	}

	private function getCount(): ?int
	{
		$count = Option::get(self::MODULE_NAME, self::COUNT_OPTION_NAME, null);

		return $count === null ? null : (int)$count;
	}

	private function getNextItemId(): ?int
	{
		$nextItemId = Option::get(self::MODULE_NAME, self::LAST_ITEM_ID_OPTION_NAME, null);

		return $nextItemId === null ? null : (int)$nextItemId;
	}

	private function saveTotalCount(string $date): void
	{
		$data = Json::decode(Option::get(self::MODULE_NAME, self::TOTAL_COUNT_OPTION_NAME, '{}'));
		$data[$date] = $this->getCount();

		Option::set(self::MODULE_NAME, self::TOTAL_COUNT_OPTION_NAME, Json::encode($data));
	}

	private function addCleanAgent(): void
	{
		$funcName = '\\' . __CLASS__ . '::clean();';
		CAgent::addAgent(
			$funcName,
			self::MODULE_NAME,
			next_exec: (new DateTime())->add('+30 days'),
		);
	}

	public static function clean(): bool
	{
		Option::delete(self::MODULE_NAME, ['name' => self::LAST_ITEM_ID_OPTION_NAME]);
		Option::delete(self::MODULE_NAME, ['name' => self::COUNT_OPTION_NAME]);
		Option::delete(self::MODULE_NAME, ['name' => self::TOTAL_COUNT_OPTION_NAME]);
		Option::delete(self::MODULE_NAME, ['name' => self::DATE_OPTION_NAME]);

		return self::AGENT_DONE;
	}
}
