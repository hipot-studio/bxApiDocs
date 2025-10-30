<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Counter;

use Bitrix\Tasks\Internals\Counter;

class RoleProvider
{
	private array $items = [];

	private const COUNTERS_MAP = [
		Counter\Role::RESPONSIBLE => Counter\CounterDictionary::COUNTER_MY,
		Counter\Role::ACCOMPLICE => Counter\CounterDictionary::COUNTER_ACCOMPLICES,
		Counter\Role::ORIGINATOR => Counter\CounterDictionary::COUNTER_ORIGINATOR,
		Counter\Role::AUDITOR => Counter\CounterDictionary::COUNTER_AUDITOR,
	];

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
				'COUNTER' => Counter::getInstance($userId)->get(self::COUNTERS_MAP[$roleId]),
			];
		}

		return $this->items[$userId];
	}
}
