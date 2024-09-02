<?php

namespace Bitrix\Crm\Security\Role\Manage\Permissions;

/**
 * Base class for permissions
 * Don't forget to add new permission to list self::permissionList
 */
abstract class Permission
{
	public function __construct(private readonly array $variants = [])
	{

	}

	abstract public function code(): string;

	abstract public function name(): string;

	abstract public function canAssignPermissionToStages(): bool;

	public function variants(): array
	{
		return $this->variants;
	}

	public function toArray(): array
	{
		return [
			'code' => $this->code(),
			'name' => $this->name(),
			'variants' => $this->variants(),
			'canAssignPermissionToStages' => $this->canAssignPermissionToStages(),
			'defaultAttribute' => $this->getDefaultAttribute(),
			'defaultSettings' => $this->getDefaultSettings(),
		];
	}

	public function sortOrder(): ?int
	{
		return 999;
	}

	public function getDefaultAttribute(): ?string
	{
		return null;
	}

	public function getDefaultSettings(): array
	{
		return [];
	}
}
