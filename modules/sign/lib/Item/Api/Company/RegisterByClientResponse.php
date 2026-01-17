<?php

namespace Bitrix\Sign\Item\Api\Company;

use Bitrix\Sign\Item;

class RegisterByClientResponse extends Item\Api\Response
{
	public ?string $id;

	public function __construct(?string $id)
	{
		$this->id = $id; // uid
	}
}
