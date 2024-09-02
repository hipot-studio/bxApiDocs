<?php

namespace Bitrix\Sign\Item;

use Bitrix\Sign\Contract\Item;

class CompanyProvider implements Item
{
	public function __construct(
		public string $code,
		public string $uid,
		public int $timestamp,
		public bool $virtual = false,
		public bool $autoRegister = false,
		public ?int $expires = null,
	) {}
}
