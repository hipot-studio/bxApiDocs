<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Debugger\Collections;

use ArrayIterator;
use Bitrix\Bizproc\Internal\Entity\BaseEntityCollection;
use Bitrix\Bizproc\Internal\Entity\Debugger\DebugTrace;

/**
 * @method DebugTrace|null getFirstCollectionItem()
 * @method ArrayIterator<DebugTrace> getIterator()
 */
class DebugTraceCollection extends BaseEntityCollection
{
	public function __construct(DebugTrace ...$traces)
	{
		foreach ($traces as $trace)
		{
			$this->collectionItems[] = $trace;
		}
	}

	public function filterByType(string $type): self
	{
		$filtered = [];

		foreach ($this as $trace)
		{
			/** @var DebugTrace $trace */
			if ($trace->getType() === $type)
			{
				$filtered[] = $trace;
			}
		}

		return new self(...$filtered);
	}

	public function sortByTimestamp(string $direction = 'ASC'): self
	{
		$items = [];

		foreach ($this as $trace)
		{
			/** @var DebugTrace $trace */
			$items[] = $trace;
		}

		usort($items, function (DebugTrace $a, DebugTrace $b) use ($direction) {
			$aValue = $a->getTimestamp()?->getValue() ?? 0;
			$bValue = $b->getTimestamp()?->getValue() ?? 0;
			$result = $aValue <=> $bValue;

			return $direction === 'DESC' ? -$result : $result;
		});

		return new self(...$items);
	}

	public function getFirst(): ?DebugTrace
	{
		if ($this->isEmpty())
		{
			return null;
		}

		foreach ($this as $trace)
		{
			return $trace;
		}

		return null;
	}

	public function getLast(): ?DebugTrace
	{
		if ($this->isEmpty())
		{
			return null;
		}

		$last = null;

		foreach ($this as $trace)
		{
			/** @var DebugTrace $trace */
			$last = $trace;
		}

		return $last;
	}

	public function getByTraceId(string $traceId): ?DebugTrace
	{
		foreach ($this as $trace)
		{
			/** @var DebugTrace $trace */
			if ($trace->getKey() === $traceId)
			{
				return $trace;
			}
		}

		return null;
	}

	public function getTypes(): array
	{
		$types = [];

		foreach ($this as $trace)
		{
			/** @var DebugTrace $trace */
			$types[] = $trace->getType();
		}

		return array_unique($types);
	}
}
