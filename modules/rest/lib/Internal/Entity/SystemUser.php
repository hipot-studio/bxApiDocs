<?php

namespace Bitrix\Rest\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Rest\Internal\Entity\SystemUser\AccountType;
use Bitrix\Rest\Internal\Entity\SystemUser\ResourceType;

class SystemUser implements EntityInterface
{
	public function __construct(
		private ?int $id = null,
		private ?int $userId = null,
		private ?AccountType $accountType = null,
		private ?ResourceType $resourceType = null,
		private ?int $resourceId = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): SystemUser
	{
		$this->id = $id;
		return $this;
	}


	public function getUserId(): ?int
	{
		return $this->userId;
	}

	public function setUserId(?int $userId): SystemUser
	{
		$this->userId = $userId;

		return $this;
	}

	public function getAccountType(): ?AccountType
	{
		return $this->accountType;
	}

	public function setAccountType(?AccountType $accountType): SystemUser
	{
		$this->accountType = $accountType;

		return $this;
	}

	public function getResourceType(): ?ResourceType
	{
		return $this->resourceType;
	}

	public function setResourceType(?ResourceType $resourceType): SystemUser
	{
		$this->resourceType = $resourceType;

		return $this;
	}

	public function getResourceId(): ?int
	{
		return $this->resourceId;
	}

	public function setResourceId(?int $resourceId): SystemUser
	{
		$this->resourceId = $resourceId;

		return $this;
	}
}