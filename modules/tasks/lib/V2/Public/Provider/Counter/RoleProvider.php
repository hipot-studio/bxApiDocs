<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Counter;

use Bitrix\Tasks\Internals\Counter;

class RoleProvider
{
	private array $items = [];

	public function getItems(int $userId): array
	{
		if (isset($this->items[$userId]))
		{
			return $this->items[$userId];
		}

		$this->items[$userId] = [];

		foreach (Counter\Role::getRoles() as $roleId => $role)
		{
			$this->items[$userId][$roleId] = [
				'TEXT' => $role['TITLE'],
			];
		}

		return $this->items[$userId];
	}
}
