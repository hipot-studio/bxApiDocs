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
		private ?int $disabledById = null,
		private ?DateTime $dateDisabled = null,
		private ?DateTime $dateLastOpened = null,
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

	public function setDateCreate(DateTime $dateCreate): self
	{
		$this->dateCreate = $dateCreate;

		return $this;
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

	public function getDisabledById(): ?int
	{
		return $this->disabledById;
	}

	public function getDateDisabled(): ?DateTime
	{
		return $this->dateDisabled;
	}

	/**
	 * Links switched off before DATE_DISABLED existed carry the moment only in DATE_MODIFY.
	 */
	public function getEffectiveDateDisabled(): ?DateTime
	{
		if ($this->isActive())
		{
			return null;
		}

		return $this->dateDisabled ?? $this->dateModify;
	}

	/**
	 * Switched off by someone other than its author, that is by a controller or by a ban.
	 */
	public function isDisabledByAdmin(): bool
	{
		return !$this->isActive() && $this->disabledById !== null && $this->disabledById !== $this->createdById;
	}

	public function getDateLastOpened(): ?DateTime
	{
		return $this->dateLastOpened;
	}

	/**
	 * Turns the link on again as a new one: new address, new creation date, no trace of the switch-off
	 * and of the old openings.
	 */
	public function enable(string $token, DateTime $now): self
	{
		$this->active = 'Y';
		$this->token = $token;
		$this->dateCreate = $now;
		$this->dateModify = $now;
		$this->disabledById = null;
		$this->dateDisabled = null;
		$this->dateLastOpened = null;

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
