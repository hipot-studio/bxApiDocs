<?php

namespace Bitrix\Sign\Item\Api\Company;

class DeleteRequest implements \Bitrix\Sign\Contract\Item
{
	public function __construct(
		public string $id,
	)
	{
	}
}

