<?php

namespace Bitrix\Sign\Item\Api\Company;

use Bitrix\Sign\Item;

class GetResponse extends Item\Api\Response
{
	public array $companies;

	public function __construct(array $companies)
	{
		$this->companies = $companies;
	}
}
