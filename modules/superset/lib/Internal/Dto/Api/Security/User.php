<?php

namespace Bitrix\Superset\Internal\Dto\Api\Security;

class User
{
	public ?int $id = null;
	public ?array $roles = [];

	public function __construct(
		public string $userName,
		public string $firstName,
		public string $lastName,
		public string $email,
		public string $password,
		public bool $active = true,
	)
	{}
}
