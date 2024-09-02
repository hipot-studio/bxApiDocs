<?php

namespace Bitrix\Sign\Contract;

interface ItemCollection
{
	/**
	 * @return Item[]
	 */
	public function toArray(): array;
}