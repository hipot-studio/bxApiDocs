<?php

namespace Bitrix\Crm\Security\Role\Manage\DTO;

class GridData
{

	public function __construct(
		private RoleDTO $role,
		/** @var array<string, EntityDTO> */
		private array $entities,
	)
	{
	}

	public function role(): RoleDTO
	{
		return $this->role;
	}

	/**
	 * @return EntityDTO[]
	 */
	public function entities(): array
	{
		return $this->entities;
	}
}