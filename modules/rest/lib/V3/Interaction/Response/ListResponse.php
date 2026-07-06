<?php

namespace Bitrix\Rest\V3\Interaction\Response;

use Bitrix\Rest\V3\Dto\DtoCollection;

class ListResponse extends ResponseWithRelations
{

	public function __construct(public DtoCollection $items)
	{
	}
}
