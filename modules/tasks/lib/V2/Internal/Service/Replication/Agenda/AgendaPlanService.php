<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Agenda;

use Bitrix\Tasks\Internals\Task\Template\TemplateObject;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\Replication\Template\Time\Service\ScheduleSequenceService;
use Bitrix\Tasks\V2\Internal\Service\Replication\AfterCompletionPlanService;

class AgendaPlanService
{
	private const DAY_SECONDS = 86400;

	private readonly AfterCompletionPlanService $afterCompletionPlanService;

	public function __construct(
		private readonly ScheduleSequenceService $sequenceService,
		?AfterCompletionPlanService $afterCompletionPlanService = null,
	)
	{
		$this->afterCompletionPlanService = $afterCompletionPlanService ?? new AfterCompletionPlanService();
	}

	/**
	 * @param array<int, TemplateObject> $templates
	 * @param array{from:int,to:int,limit:int,cursor:?array{ts:int,id:int}}|null $todayPage
	 * @param array{from:int,to:int,limit:int,cursor:?array{ts:int,id:int}}|null $futurePage
	 * @return array{
	 *   count:int,
	 *   today:array<int,array{when:int,templateId:int,name:string}>,
	 *   future:array<int,array{when:int,templateId:int,name:string}>
	 * }
	 */
	public function build(
		array $templates,
		?int $countFrom,
		?int $countTo,
		?array $todayPage,
		?array $futurePage,
	): array
	{
		$todayCollector = new AgendaPlanCollector((int)($todayPage['limit'] ?? 0));
		$futureCollector = new AgendaPlanCollector((int)($futurePage['limit'] ?? 0));
		$count = 0;

		if ($templates === [] || !$this->hasRequestedWindow($countFrom, $countTo, $todayPage, $futurePage))
		{
			return ['count' => 0, 'today' => [], 'future' => []];
		}

		$afterCompletionPlan = $this->afterCompletionPlanService->getNextExecutionTimes(
			$this->getAfterCompletionTemplateIds($templates),
		);

		foreach ($templates as $template)
		{
			// A paused rule has no upcoming postings - only its history stays in the agenda.
			if (!$template->getReplicate())
			{
				continue;
			}

			$templateId = (int)$template->getId();
			$name = (string)$template->getTitle();
			$type = RegularityType::fromValue($template->getReplicateType());

			if ($type === RegularityType::AfterCompletion)
			{
				$timestamp = $afterCompletionPlan[$templateId] ?? null;
				if ($timestamp !== null)
				{
					$this->collectTimestamp(
						$timestamp,
						$templateId,
						$name,
						$countFrom,
						$countTo,
						$todayPage,
						$futurePage,
						$count,
						$todayCollector,
						$futureCollector,
					);
				}

				continue;
			}

			[$sequenceFrom, $sequenceTo] = $this->resolveSequenceWindow(
				$templateId,
				$countFrom,
				$countTo,
				$todayPage,
				$futurePage,
			);
			if ($sequenceFrom === null || $sequenceTo === null || $sequenceTo < $sequenceFrom)
			{
				continue;
			}

			$sequenceLimit = $this->resolveSequenceLimit(
				$sequenceFrom,
				$sequenceTo,
				$countFrom,
				$countTo,
				$todayPage,
				$futurePage,
			);
			foreach ($this->sequenceService->getSequence($template, $sequenceFrom, $sequenceTo, $sequenceLimit) as $timestamp)
			{
				$this->collectTimestamp(
					$timestamp,
					$templateId,
					$name,
					$countFrom,
					$countTo,
					$todayPage,
					$futurePage,
					$count,
					$todayCollector,
					$futureCollector,
				);
			}
		}

		return [
			'count' => $count,
			'today' => $todayCollector->getItems(),
			'future' => $futureCollector->getItems(),
		];
	}

	/**
	 * @param array<int, TemplateObject> $templates
	 */
	public function canContinueAfter(array $templates, int $timestamp): bool
	{
		$afterCompletionTemplateIds = [];
		foreach ($templates as $template)
		{
			if (!$template->getReplicate())
			{
				continue;
			}

			if (RegularityType::fromValue($template->getReplicateType()) === RegularityType::AfterCompletion)
			{
				$afterCompletionTemplateIds[] = (int)$template->getId();
				continue;
			}

			if ($this->sequenceService->canContinueAfter($template, $timestamp))
			{
				return true;
			}
		}

		foreach ($this->afterCompletionPlanService->getNextExecutionTimes($afterCompletionTemplateIds) as $nextExecTs)
		{
			if ($nextExecTs > $timestamp)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, TemplateObject> $templates
	 * @return int[]
	 */
	private function getAfterCompletionTemplateIds(array $templates): array
	{
		$result = [];
		foreach ($templates as $template)
		{
			if (
				$template->getReplicate()
				&& RegularityType::fromValue($template->getReplicateType()) === RegularityType::AfterCompletion
			)
			{
				$result[] = (int)$template->getId();
			}
		}

		return $result;
	}

	private function collectTimestamp(
		int $timestamp,
		int $templateId,
		string $name,
		?int $countFrom,
		?int $countTo,
		?array $todayPage,
		?array $futurePage,
		int &$count,
		AgendaPlanCollector $todayCollector,
		AgendaPlanCollector $futureCollector,
	): void
	{
		if ($countFrom !== null && $countTo !== null && $timestamp >= $countFrom && $timestamp <= $countTo)
		{
			$count++;
		}

		$item = ['when' => $timestamp, 'templateId' => $templateId, 'name' => $name];
		if ($this->isInPage($item, $todayPage))
		{
			$todayCollector->add($item);
		}
		if ($this->isInPage($item, $futurePage))
		{
			$futureCollector->add($item);
		}
	}

	/**
	 * @param array{when:int,templateId:int,name:string} $item
	 * @param array{from:int,to:int,limit:int,cursor:?array{ts:int,id:int}}|null $page
	 */
	private function isInPage(array $item, ?array $page): bool
	{
		if ($page === null || $item['when'] < $page['from'] || $item['when'] > $page['to'])
		{
			return false;
		}

		$cursor = $page['cursor'];

		return $cursor === null
			|| [$item['when'], $item['templateId']] > [$cursor['ts'], $cursor['id']]
		;
	}

	/**
	 * @return array{0:?int,1:?int}
	 */
	private function resolveSequenceWindow(
		int $templateId,
		?int $countFrom,
		?int $countTo,
		?array $todayPage,
		?array $futurePage,
	): array
	{
		$from = [];
		$to = [];

		if ($countFrom !== null && $countTo !== null)
		{
			$from[] = $countFrom;
			$to[] = $countTo;
		}

		foreach ([$todayPage, $futurePage] as $page)
		{
			if ($page === null)
			{
				continue;
			}

			$pageFrom = $page['from'];
			$cursor = $page['cursor'];
			if ($cursor !== null)
			{
				$pageFrom = max($pageFrom, $cursor['ts'] + ($templateId <= $cursor['id'] ? 1 : 0));
			}

			$from[] = $pageFrom;
			$to[] = $page['to'];
		}

		return $from === [] ? [null, null] : [min($from), max($to)];
	}

	private function resolveSequenceLimit(
		int $sequenceFrom,
		int $sequenceTo,
		?int $countFrom,
		?int $countTo,
		?array $todayPage,
		?array $futurePage,
	): int
	{
		if ($countFrom !== null && $countTo !== null)
		{
			return intdiv($sequenceTo - $sequenceFrom, self::DAY_SECONDS) + 2;
		}

		return (int)($todayPage['limit'] ?? 0) + (int)($futurePage['limit'] ?? 0);
	}

	private function hasRequestedWindow(
		?int $countFrom,
		?int $countTo,
		?array $todayPage,
		?array $futurePage,
	): bool
	{
		return ($countFrom !== null && $countTo !== null) || $todayPage !== null || $futurePage !== null;
	}
}
