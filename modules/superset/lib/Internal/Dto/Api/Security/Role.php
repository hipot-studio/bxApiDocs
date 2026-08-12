<?php

namespace Bitrix\Superset\Internal\Dto\Api\Security;

class Role
{
	public ?int $id = null;

	public function __construct(
		public string $name,
	)
	{}
}
