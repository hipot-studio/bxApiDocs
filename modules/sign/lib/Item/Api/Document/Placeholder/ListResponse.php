<?php

namespace Bitrix\Sign\Item\Api\Document\Placeholder;

use Bitrix\Sign\Item;
use Bitrix\Sign\Item\Api\Property;

class ListResponse extends Item\Api\Response
{
	public array $list;

	public function __construct(array $list)
	{
		$this->list = $list;
	}
}