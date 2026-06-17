<?php

namespace Bitrix\BIConnector\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

class SupersetDashboardShare implements EntityInterface
{
	private ?int $id = null;

	public function __construct(
		private readonly int $dashboardId,
		private string $token,
		private string $password,
		private DateTime $dateExpire,
		private string $active = 'Y',
		private int $createdById = 0,
		private ?DateTime $dateCreate = null,
		private ?DateTime $dateModify = null,
		private ?string $externalFilterValues = null,
		private ?string $urlParameterValues = null,
		private int $loginAttempts = 0,
		private ?DateTime $loginLockedTill = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getDashboardId(): int
	{
		return $this->dashboardId;
	}

	public function getToken(): string
	{
		return $this->token;
	}

	public function setToken(string $token): self
	{
		$this->token = $token;

		return $this;
	}

	public function getPassword(): string
	{
		return $this->password;
	}

	public function setPassword(string $password): self
	{
		$this->password = $password;

		return $this;
	}

	public function getDateExpire(): DateTime
	{
		return $this->dateExpire;
	}

	public function setDateExpire(DateTime $dateExpire): self
	{
		$this->dateExpire = $dateExpire;

		return $this;
	}

	public function getActive(): string
	{
		return $this->active;
	}

	public function setActive(string $active): self
	{
		$this->active = $active;

		return $this;
	}

	public function isActive(): bool
	{
		return $this->active === 'Y';
	}

	public function getCreatedById(): int
	{
		return $this->createdById;
	}

	public function setCreatedById(int $createdById): self
	{
		$this->createdById = $createdById;

		return $this;
	}

	public function getDateCreate(): ?DateTime
	{
		return $this->dateCreate;
	}

	public function getDateModify(): ?DateTime
	{
		return $this->dateModify;
	}

	public function setDateModify(?DateTime $dateModify): self
	{
		$this->dateModify = $dateModify;

		return $this;
	}

	public function getExternalFilterValues(): ?string
	{
		return $this->externalFilterValues;
	}

	public function setExternalFilterValues(?string $externalFilterValues): self
	{
		$this->externalFilterValues = $externalFilterValues;

		return $this;
	}

	public function getUrlParameterValues(): ?string
	{
		return $this->urlParameterValues;
	}

	public function setUrlParameterValues(?string $urlParameterValues): self
	{
		$this->urlParameterValues = $urlParameterValues;

		return $this;
	}

	public function getLoginAttempts(): int
	{
		return $this->loginAttempts;
	}

	public function setLoginAttempts(int $loginAttempts): self
	{
		$this->loginAttempts = $loginAttempts;

		return $this;
	}

	public function getLoginLockedTill(): ?DateTime
	{
		return $this->loginLockedTill;
	}

	public function setLoginLockedTill(?DateTime $loginLockedTill): self
	{
		$this->loginLockedTill = $loginLockedTill;

		return $this;
	}

	public function isValid(): bool
	{
		if (!$this->isActive())
		{
			return false;
		}

		return $this->dateExpire->getTimestamp() >= time();
	}

	public function isExpired(): bool
	{
		return $this->dateExpire->getTimestamp() < time();
	}
}
