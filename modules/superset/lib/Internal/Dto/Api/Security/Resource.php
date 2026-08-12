<?php

namespace Bitrix\Superset\Internal\Dto\Api\Security;

class Resource
{
	public ?int $id = null;

	public function __construct(
		public string $name,
	)
	{}
}
