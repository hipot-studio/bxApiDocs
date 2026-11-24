<?php

namespace Bitrix\Sale\Exchange\Entity;

use Bitrix\Sale\BasketItem;

/** @internal */
final class BasketItemCandidateList implements \IteratorAggregate
{
	private array $items = [];
	private array $previous = [];
	private array $next = [];
	private int|string|null $head = null;
	private int|string|null $tail = null;

	public function __construct(iterable $basketItems = [])
	{
		foreach ($basketItems as $basketItem)
		{
			$this->add($basketItem);
		}
	}

	public function add(BasketItem $basketItem): void
	{
		$code = $basketItem->getBasketCode();
		$this->items[$code] = $basketItem;
		$this->previous[$code] = $this->tail;
		$this->next[$code] = null;
		if ($this->tail === null)
		{
			$this->head = $code;
		}
		else
		{
			$this->next[$this->tail] = $code;
		}
		$this->tail = $code;
	}

	public function remove(int|string $code): void
	{
		if (!isset($this->items[$code]))
		{
			return;
		}

		$previous = $this->previous[$code];
		$next = $this->next[$code];
		if ($previous === null)
		{
			$this->head = $next;
		}
		else
		{
			$this->next[$previous] = $next;
		}
		if ($next === null)
		{
			$this->tail = $previous;
		}
		else
		{
			$this->previous[$next] = $previous;
		}
		unset($this->items[$code], $this->previous[$code], $this->next[$code]);
	}

	public function getIterator(): \Traversable
	{
		$code = $this->head;
		while ($code !== null)
		{
			yield $code => $this->items[$code];
			$code = $this->next[$code];
		}
	}
}
