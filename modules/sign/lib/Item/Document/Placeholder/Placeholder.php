<?php

namespace Bitrix\Sign\Item\Document\Placeholder;

use Bitrix\Sign\Contract;

class Placeholder implements Contract\Item
{
	public function __construct(
		public readonly string $name,
		public readonly string $value,
	)
	{
	}
}
