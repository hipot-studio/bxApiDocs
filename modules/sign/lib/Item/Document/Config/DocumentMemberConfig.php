<?php

namespace Bitrix\Sign\Item\Document\Config;

use Bitrix\Sign\Contract;

class DocumentMemberConfig implements Contract\Item
{
	public function __construct(
		public int $userId,
		public ?int $employeeId,
		public ?string $role,
	)
	{}
}
