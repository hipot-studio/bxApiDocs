<?php

namespace Bitrix\Crm\RepeatSale\Statistics;

use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\RepeatSale\Segment\DataFormatter;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use CCrmCurrency;
use CTimeZone;

final class DataProvider
{
	public function __construct(
		private readonly StatisticsReader $statisticsReader = new StatisticsReader(),
	)
	{

	}

	public function getData(PeriodType $periodType): array
	{
		$startDate = $this->getStartDate($periodType);
		$periodStartDate = Date::createFromTimestamp($startDate->getTimestamp())->toString();

		$totals = $this->readExistingSegmentTotals($this->toServerTime($startDate));
		if ($totals === [])
		{
			return $this->getEmptyData($periodStartDate);
		}

		$segmentIds = [];
		$createdCountForPeriod = 0;
		$processCount = 0;
		$processSum = 0.0;
		$winCount = 0;
		$winSum = 0.0;

		/** @var array<int, array<string, array{count: int, sum: float}>> $bySegment */
		$bySegment = [];

		foreach ($totals as $row)
		{
			$segmentId = $row['SEGMENT_ID'];
			$segmentIds[$segmentId] = $segmentId;
			$createdCountForPeriod += $row['CNT'];

			$semanticId = $row['STAGE_SEMANTIC_ID'];
			$bySegment[$segmentId][$semanticId] = [
				'count' => $row['CNT'],
				'sum' => $row['AMOUNT'],
			];

			if ($semanticId === PhaseSemantics::PROCESS)
			{
				$processCount += $row['CNT'];
				$processSum += $row['AMOUNT'];
			}
			elseif ($semanticId === PhaseSemantics::SUCCESS)
			{
				$winCount += $row['CNT'];
				$winSum += $row['AMOUNT'];
			}
		}

		$createdCountToday = $this->countCreatedToday($segmentIds);

		return [
			'repeatSaleWinSum' => $this->format($winSum),
			'repeatSaleWinCount' => $winCount,

			'repeatSaleProcessSum' => $this->format($processSum),
			'repeatSaleProcessCount' => $processCount,

			'repeatSaleTotalCount' => $createdCountForPeriod,
			'repeatSaleTodayCount' => $createdCountToday,

			'conversionByCount' => $createdCountForPeriod > 0 ? round($winCount / $createdCountForPeriod * 100, 1) : 0,

			'repeatSaleSegmentIds' => array_values($segmentIds),
			'repeatSaleSegmentStats' => $this->getSegmentStats($bySegment),
			'repeatSalePeriodStartDate' => $periodStartDate,
		];
	}

	private function getEmptyData(string $periodStartDate): array
	{
		return [
			'repeatSaleWinSum' => $this->format(0),
			'repeatSaleWinCount' => 0,
			'repeatSaleProcessSum' => $this->format(0),
			'repeatSaleProcessCount' => 0,
			'repeatSaleTotalCount' => 0,
			'repeatSaleTodayCount' => 0,
			'conversionByCount' => 0.0,
			'repeatSaleSegmentIds' => [],
			'repeatSaleSegmentStats' => [],
			'repeatSalePeriodStartDate' => $periodStartDate,
		];
	}

	/**
	 * Totals of the period without the scenarios that no longer exist: their log rows stay behind after
	 * a scenario is deleted, and deals nobody can reach through the scenario filter of the grid must not
	 * be counted here either.
	 *
	 * @return array<int, array{SEGMENT_ID: int, STAGE_SEMANTIC_ID: string, CNT: int, AMOUNT: float}>
	 */
	private function readExistingSegmentTotals(DateTime $periodStart): array
	{
		return array_values(
			array_filter(
				$this->statisticsReader->readSegmentTotals($periodStart),
				static fn (array $row): bool => DataFormatter::getInstance()->getTitle($row['SEGMENT_ID']) !== null,
			),
		);
	}

	/**
	 * Deals of the user's own day, counted over the scenarios the period itself counts - a row of a
	 * deleted scenario is out of both numbers.
	 *
	 * @param array<int, int> $segmentIds
	 */
	private function countCreatedToday(array $segmentIds): int
	{
		$counts = $this->statisticsReader->readSegmentCreatedCounts($this->getTodayStart());

		$count = 0;
		foreach ($counts as $segmentId => $segmentCount)
		{
			if (isset($segmentIds[$segmentId]))
			{
				$count += $segmentCount;
			}
		}

		return $count;
	}

	/**
	 * @param array<int, array<string, array{count: int, sum: float}>> $bySegment
	 */
	private function getSegmentStats(array $bySegment): array
	{
		$rows = [];

		foreach ($bySegment as $segmentId => $bySemantic)
		{
			$title = DataFormatter::getInstance()->getTitle($segmentId);
			if ($title === null)
			{
				continue;
			}

			$process = $bySemantic[PhaseSemantics::PROCESS] ?? ['count' => 0, 'sum' => 0.0];
			$win = $bySemantic[PhaseSemantics::SUCCESS] ?? ['count' => 0, 'sum' => 0.0];

			$totalCount = 0;
			foreach ($bySemantic as $semanticTotals)
			{
				$totalCount += $semanticTotals['count'];
			}

			$rows[] = [
				'totalCount' => $totalCount,
				'title' => $title,
				'data' => [
					'id' => $segmentId,
					'title' => $title,
					'processCount' => $process['count'],
					'processSum' => $this->format($process['sum']),
					'winCount' => $win['count'],
					'winSum' => $this->format($win['sum']),
				],
			];
		}

		usort(
			$rows,
			static function (array $a, array $b): int {
				return ($b['totalCount'] <=> $a['totalCount']) ?: ($a['title'] <=> $b['title']);
			},
		);

		return array_column($rows, 'data');
	}

	private function format(float $sum): string
	{
		return CCrmCurrency::MoneyToString(round($sum), $this->getCurrency());
	}

	/**
	 * Start of the period in user time: the deal grid filter reads dates as user time, so counting statistics
	 * from the server midnight would put the widget counters and the filtered grid on different boundaries.
	 */
	private function getStartDate(PeriodType $periodType): DateTime
	{
		$dateTime = $this->toUserTime(new DateTime());

		$dateTime->add(match ($periodType) {
			PeriodType::Day30 => '-30 days',
			PeriodType::Quarter => '-3 months',
			PeriodType::HalfYear => '-6 months',
			PeriodType::Year => '-12 months',
		});

		return $dateTime->setTime(0, 0);
	}

	/**
	 * The offset is taken for the date being converted, not for the present moment: a period that starts on
	 * the other side of a daylight saving change has an offset of its own, and the grid filter converts the
	 * same date with the same date-aware offset.
	 */
	private function toServerTime(DateTime $userTime): DateTime
	{
		return (clone $userTime)->add(-CTimeZone::GetOffset(null, false, $userTime) . ' seconds');
	}

	private function toUserTime(DateTime $serverTime): DateTime
	{
		return (clone $serverTime)->add(CTimeZone::GetOffset(null, false, $serverTime) . ' seconds');
	}

	// midnight of the user's own day, expressed in server time so it can be compared with the deal date
	private function getTodayStart(): DateTime
	{
		return $this->toServerTime($this->toUserTime(new DateTime())->setTime(0, 0));
	}

	private function getCurrency(): ?string
	{
		return CCrmCurrency::GetAccountCurrencyID();
	}
}
