<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity;

use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Entity\EntityInterface;

abstract class IdentifiableEntityCollection extends EntityCollection
{
	public function findById(mixed $id): ?EntityInterface
	{
		return $this->find(fn (EntityInterface $item) => $item->getId() === $id);
	}

	public function removeItem(EntityInterface $item): void
	{
		unset($this->items[array_search($item, $this->items)]);
	}

	public function sort(callable $callback): void
	{
		$items = array_values($this->items);
		usort($items, $callback);
		$this->items = $items;
	}

	public function sortByIdOrder(array $idOrder): void
	{
		$idOrder = array_flip($idOrder);
		$this->sort(function (EntityInterface $a, EntityInterface $b) use ($idOrder) {
			$aId = $a->getId();
			$bId = $b->getId();
			$aPos = $idOrder[$aId] ?? PHP_INT_MAX;
			$bPos = $idOrder[$bId] ?? PHP_INT_MAX;

			if ($aPos === $bPos)
			{
				return 0;
			}

			return $aPos <=> $bPos;
		});
	}
}
