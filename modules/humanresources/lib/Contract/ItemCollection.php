<?php

namespace Bitrix\HumanResources\Contract;

/**
 * @extends	 \IteratorAggregate<T, V>
 * @template T
 * @template V
 */
interface ItemCollection extends \IteratorAggregate
{
	/**
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 */
	public function add(Item $item): static;
}