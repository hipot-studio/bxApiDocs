<?php

namespace Bitrix\HumanResources\Item\HcmLink;

use Bitrix\HumanResources\Contract\Item;
use Bitrix\Main\Type\DateTime;

class Exclusion implements Item
{
	public function __construct(
		public int $companyId,
		public int $userId,
		public int $createdBy,
		public ?DateTime $createdAt = null,
		public ?int $id = null,
	)
	{
	}
}
