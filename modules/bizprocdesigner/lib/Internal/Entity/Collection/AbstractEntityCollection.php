<?php

namespace Bitrix\BizprocDesigner\Internal\Entity\Collection;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\Contract\Arrayable;

/**
 * @psalm-consistent-constructor
 * @template T of EntityInterface
 * @implements \IteratorAggregate<int, T>
 */
abstract class AbstractEntityCollection extends AbstractCollection implements Arrayable
{
	/**
	 * @var array<EntityInterface & Arrayable>
	 */
	protected array $items = [];

	abstract protected function createEntityFromArray(array $data): EntityInterface;

	/**
	 * @param array $data
	 * @return $this
	 */
	public function fill(array $data): self
	{
		foreach ($data as $element)
		{
			if (!is_array($element))
			{
				throw new \InvalidArgumentException('Expected array, got ' . gettype($element));
			}

			$entity = $this->createEntityFromArray($element);
			$this->add($entity);
		}

		return $this;
	}
	/**
	 * @return array
	 */
	public function toArray(): array
	{
		$result = [];
		foreach ($this->items as $item)
		{
			$result[] = $item->toArray();
		}

		return $result;
	}
}