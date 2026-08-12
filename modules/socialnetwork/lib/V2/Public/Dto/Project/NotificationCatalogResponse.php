<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

use Bitrix\Main\Type\Contract\Arrayable;

/**
 * DTO-01: catalog of notification types returned to the client when reading a project.
 *
 * Structure:
 * {
 *   "groups": [
 *     {
 *       "id": "members",
 *       "label": "Участники",
 *       "types": [
 *         {"id": "member_add_employee", "label": "Добавлен сотрудник", "counterEnabled": false},
 *         ...
 *       ]
 *     },
 *     ...
 *   ]
 * }
 */
class NotificationCatalogResponse implements Arrayable
{
	/** @var list<array{id: string, label: string, types: list<array{id: string, label: string, counterEnabled: bool}>}> */
	private array $groups;

	public function __construct(array $groups)
	{
		$this->groups = $groups;
	}

	public function toArray(): array
	{
		return [
			'groups' => $this->groups,
		];
	}
}
