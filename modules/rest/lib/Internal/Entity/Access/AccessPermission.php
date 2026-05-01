<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Access;

use Bitrix\Main\Entity\EntityInterface;

class AccessPermission implements EntityInterface
{
	private ?int $id;
	private EntityType $entityType;
	private string $accessCode;
	private PermissionType $permission;

	public function __construct(
		EntityType $entityType,
		string $accessCode,
		PermissionType $permission,
		?int $id = null,
	)
	{
		$this->id = $id;
		$this->entityType = $entityType;
		$this->accessCode = $accessCode;
		$this->permission = $permission;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getEntityType(): EntityType
	{
		return $this->entityType;
	}

	public function setEntityType(EntityType $entityType): self
	{
		$this->entityType = $entityType;

		return $this;
	}

	public function getAccessCode(): string
	{
		return $this->accessCode;
	}

	public function setAccessCode(string $accessCode): self
	{
		$this->accessCode = $accessCode;

		return $this;
	}

	public function getPermission(): PermissionType
	{
		return $this->permission;
	}

	public function setPermission(PermissionType $permission): self
	{
		$this->permission = $permission;

		return $this;
	}
}
