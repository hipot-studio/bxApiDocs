<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Agenda;

use SplPriorityQueue;

final class AgendaPlanCollector
{
	private AgendaPlanPriorityQueue $queue;

	public function __construct(private readonly int $limit)
	{
		$this->queue = new AgendaPlanPriorityQueue();
	}

	/**
	 * @param array{when:int, templateId:int, name:string} $item
	 */
	public function add(array $item): void
	{
		if ($this->limit < 1)
		{
			return;
		}

		$this->queue->insert($item, [$item['when'], $item['templateId']]);
		if ($this->queue->count() > $this->limit)
		{
			$this->queue->extract();
		}
	}

	/**
	 * @return array<int, array{when:int, templateId:int, name:string}>
	 */
	public function getItems(): array
	{
		$queue = clone $this->queue;
		$queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);

		$items = [];
		while (!$queue->isEmpty())
		{
			$items[] = $queue->extract();
		}

		return array_reverse($items);
	}
}

final class AgendaPlanPriorityQueue extends SplPriorityQueue
{
	public function compare(mixed $priority1, mixed $priority2): int
	{
		return $priority1 <=> $priority2;
	}
}
