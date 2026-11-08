<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application\AppInstallRequest;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

class AppInstallRequest implements EntityInterface
{
	private ?int $id = null;

	public function __construct(
		private string $appCode,
		private int $userId,
		private AppInstallRequestStatus $status = AppInstallRequestStatus::Pending,
		private string $appVersion = '1',
		private ?string $checkHash = null,
		private ?string $installHash = null,
		private ?string $appName = null,
		private ?string $appIconUrl = null,
		private ?int $resolvedById = null,
		private ?DateTime $dateCreate = null,
		private ?DateTime $dateResolve = null,
	)
	{
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

	public function getAppCode(): string
	{
		return $this->appCode;
	}

	public function setAppCode(string $appCode): self
	{
		$this->appCode = $appCode;

		return $this;
	}

	public function getAppVersion(): string
	{
		return $this->appVersion;
	}

	public function setAppVersion(string $appVersion): self
	{
		$this->appVersion = $appVersion;

		return $this;
	}

	public function getCheckHash(): ?string
	{
		return $this->checkHash;
	}

	public function setCheckHash(?string $checkHash): self
	{
		$this->checkHash = $checkHash;

		return $this;
	}

	public function getInstallHash(): ?string
	{
		return $this->installHash;
	}

	public function setInstallHash(?string $installHash): self
	{
		$this->installHash = $installHash;

		return $this;
	}

	public function getAppName(): ?string
	{
		return $this->appName;
	}

	public function setAppName(?string $appName): self
	{
		$this->appName = $appName;

		return $this;
	}

	public function getAppIconUrl(): ?string
	{
		return $this->appIconUrl;
	}

	public function setAppIconUrl(?string $appIconUrl): self
	{
		$this->appIconUrl = $appIconUrl;

		return $this;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function setUserId(int $userId): self
	{
		$this->userId = $userId;

		return $this;
	}

	public function getStatus(): AppInstallRequestStatus
	{
		return $this->status;
	}

	public function setStatus(AppInstallRequestStatus $status): self
	{
		$this->status = $status;

		return $this;
	}

	public function getResolvedById(): ?int
	{
		return $this->resolvedById;
	}

	public function setResolvedById(?int $resolvedById): self
	{
		$this->resolvedById = $resolvedById;

		return $this;
	}

	public function getDateCreate(): ?DateTime
	{
		return $this->dateCreate;
	}

	public function setDateCreate(?DateTime $dateCreate): self
	{
		$this->dateCreate = $dateCreate;

		return $this;
	}

	public function getDateResolve(): ?DateTime
	{
		return $this->dateResolve;
	}

	public function setDateResolve(?DateTime $dateResolve): self
	{
		$this->dateResolve = $dateResolve;

		return $this;
	}
}
