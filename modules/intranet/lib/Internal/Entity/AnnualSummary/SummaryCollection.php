<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

use Bitrix\Intranet\Entity\Collection\BaseCollection;
use Closure;

/**
 * @extends BaseCollection<Summary>
 */
final class SummaryCollection extends BaseCollection
{
	private array $thresholdsByCode = [];
	private array $ordersByCode = [];

	protected static function getItemClassName(): string
	{
		return Summary::class;
	}

	public function getById(string $id): ?Summary
	{
		foreach ($this->items as $item)
		{
			if ($item->getId() === $id)
			{
				return $item;
			}
		}

		return null;
	}

	public function getByCodeMap(): array
	{
		$byCode = [];
		foreach ($this->items as $item)
		{
			$byCode[$item->getId()] = $item;
		}

		return $byCode;
	}

	public function withRanking(array $thresholdsByCode, array $ordersByCode): self
	{
		$copy = new self(...$this->items);
		$copy->thresholdsByCode = $thresholdsByCode;
		$copy->ordersByCode = $ordersByCode;

		return $copy;
	}

	public function filter(Closure $callback): self
	{
		return parent::filter($callback)->withRanking($this->thresholdsByCode, $this->ordersByCode);
	}

	public function getTop(int $count = 5): self
	{
		if ($count <= 0)
		{
			return (new self())->withRanking($this->thresholdsByCode, $this->ordersByCode);
		}

		$candidates = array_filter($this->items, function (Summary $item): bool {
			$code = $item->getId();
			$threshold = $this->thresholdsByCode[$code] ?? null;

			return $threshold !== null
				&& isset($this->ordersByCode[$code])
				&& $item->getTotal() >= $threshold->getMin()
			;
		});
		usort($candidates, function (Summary $left, Summary $right): int {
			$comparison = $right->getCoefficient($this->thresholdsByCode[$right->getId()])->getValue()
				<=> $left->getCoefficient($this->thresholdsByCode[$left->getId()])->getValue()
			;

			return $comparison !== 0
				? $comparison
				: $this->ordersByCode[$left->getId()] <=> $this->ordersByCode[$right->getId()]
			;
		});

		return (new self(...array_slice($candidates, 0, $count)))
			->withRanking($this->thresholdsByCode, $this->ordersByCode)
		;
	}
}
