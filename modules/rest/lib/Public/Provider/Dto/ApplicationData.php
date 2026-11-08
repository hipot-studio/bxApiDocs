<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Dto;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

final readonly class ApplicationData
{
	private ?DateTime $dateCreate;
	private ?DateTime $dateInstall;
	private ?Date $dateFinish;

	/**
	 * @param ?list<string> $scope
	 * @param ?array<string, ApplicationLanguageData> $languages
	 */
	public function __construct(
		private ?int $id = null,
		private ?string $clientId = null,
		private ?string $code = null,
		private ?bool $active = null,
		private ?bool $installed = null,
		private ?string $url = null,
		private ?string $demoUrl = null,
		private ?string $installUrl = null,
		private ?string $settingsUrl = null,
		private ?string $version = null,
		private ?array $scope = null,
		private ?ApplicationStatus $status = null,
		?DateTime $dateCreate = null,
		?DateTime $dateInstall = null,
		?Date $dateFinish = null,
		private ?bool $isTrialed = null,
		private ?string $sharedKey = null,
		private ?string $clientSecret = null,
		private ?string $name = null,
		private ?string $access = null,
		private ?string $applicationToken = null,
		private ?bool $mobile = null,
		private ?bool $userInstall = null,
		private ?string $installCompletionMode = null,
		private ?array $languages = null,
	)
	{
		$this->dateCreate = $dateCreate === null ? null : clone $dateCreate;
		$this->dateInstall = $dateInstall === null ? null : clone $dateInstall;
		$this->dateFinish = $dateFinish === null ? null : clone $dateFinish;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getClientId(): ?string
	{
		return $this->clientId;
	}

	public function getCode(): ?string
	{
		return $this->code;
	}

	public function isActive(): ?bool
	{
		return $this->active;
	}

	public function isInstalled(): ?bool
	{
		return $this->installed;
	}

	public function getUrl(): ?string
	{
		return $this->url;
	}

	public function getDemoUrl(): ?string
	{
		return $this->demoUrl;
	}

	public function getInstallUrl(): ?string
	{
		return $this->installUrl;
	}

	public function getSettingsUrl(): ?string
	{
		return $this->settingsUrl;
	}

	public function getVersion(): ?string
	{
		return $this->version;
	}

	/**
	 * @return ?list<string>
	 */
	public function getScope(): ?array
	{
		return $this->scope;
	}

	public function getStatus(): ?ApplicationStatus
	{
		return $this->status;
	}

	public function getDateCreate(): ?DateTime
	{
		return $this->dateCreate === null ? null : clone $this->dateCreate;
	}

	public function getDateInstall(): ?DateTime
	{
		return $this->dateInstall === null ? null : clone $this->dateInstall;
	}

	public function getDateFinish(): ?Date
	{
		return $this->dateFinish === null ? null : clone $this->dateFinish;
	}

	public function isTrialed(): ?bool
	{
		return $this->isTrialed;
	}

	public function getSharedKey(): ?string
	{
		return $this->sharedKey;
	}

	public function getClientSecret(): ?string
	{
		return $this->clientSecret;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function getAccess(): ?string
	{
		return $this->access;
	}

	public function getApplicationToken(): ?string
	{
		return $this->applicationToken;
	}

	public function isMobile(): ?bool
	{
		return $this->mobile;
	}

	public function isUserInstall(): ?bool
	{
		return $this->userInstall;
	}

	public function getInstallCompletionMode(): ?string
	{
		return $this->installCompletionMode;
	}

	/**
	 * @return ?array<string, ApplicationLanguageData>
	 */
	public function getLanguages(): ?array
	{
		return $this->languages;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'Id' => $this->getId(),
			'ClientId' => $this->getClientId(),
			'Code' => $this->getCode(),
			'Active' => $this->isActive(),
			'Installed' => $this->isInstalled(),
			'Url' => $this->getUrl(),
			'DemoUrl' => $this->getDemoUrl(),
			'InstallUrl' => $this->getInstallUrl(),
			'SettingsUrl' => $this->getSettingsUrl(),
			'Version' => $this->getVersion(),
			'Scope' => $this->getScope(),
			'Status' => $this->getStatus(),
			'DateCreate' => $this->getDateCreate(),
			'DateInstall' => $this->getDateInstall(),
			'DateFinish' => $this->getDateFinish(),
			'IsTrialed' => $this->isTrialed(),
			'SharedKey' => $this->getSharedKey(),
			'ClientSecret' => $this->getClientSecret(),
			'Name' => $this->getName(),
			'Access' => $this->getAccess(),
			'ApplicationToken' => $this->getApplicationToken(),
			'Mobile' => $this->isMobile(),
			'UserInstall' => $this->isUserInstall(),
			'InstallCompletionMode' => $this->getInstallCompletionMode(),
			'Languages' => $this->getLanguages(),
		];
	}

	public function __debugInfo(): array
	{
		return $this->toArray();
	}
}
