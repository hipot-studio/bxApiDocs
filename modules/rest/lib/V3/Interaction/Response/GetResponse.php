<?php

namespace Bitrix\Rest\V3\Interaction\Response;

use Bitrix\Rest\V3\Dto\Dto;

class GetResponse extends ResponseWithRelations
{
	public function __construct(public Dto $item)
	{
	}
}
