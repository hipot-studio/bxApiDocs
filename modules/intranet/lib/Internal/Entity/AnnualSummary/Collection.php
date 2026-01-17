<?php

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\Contract\Arrayable;

class Collection extends EntityCollection implements Arrayable
{
	public function toArray(): array
	{
		return $this->map(fn($item) => $item->toArray());
	}

	public function sortByRate(): void
	{
		usort($this->items, function ($a, $b): int {
			return $b->getRate() <=> $a->getRate();
		});
	}

	public static function createByArray(array $json): self
	{
		$collection = new self();
		foreach ($json as $itemData)
		{
			$collection->add(new Feature(
				id: FeatureType::from($itemData['id']),
				count: (int)$itemData['count'],
				min: (int)$itemData['min'],
				max: (int)$itemData['max'],
				randomVariation: (int)$itemData['randomVariation'],
				countVariation: (int)$itemData['countVariation'],
			));
		}

		return $collection;
	}

	public function addUnique(EntityInterface $entity): void
	{
		foreach ($this->items as $i => $item)
		{
			if ($item->getId() === $entity->getId())
			{
				$this->items[$i] = $entity;
				return;
			}
		}

		$this->add($entity);
	}

	public function getTop(int $count = 5): self
	{
		$filtered = $this->filter(function (Feature $feature) {
			return $feature->isMoreThenMin();
		});

		$collection = new self(...array_slice($filtered->items, 0, $count));
		$first = $collection->getIterator()->current();
		$collection->add(new WinnerFeature($first));

		return $collection;
	}

	public function filterById(string $id): self
	{
		return $this->filter(function (Arrayable $feature) use ($id) {
			return $feature->toArray()['id'] === $id;
		});
	}
}
