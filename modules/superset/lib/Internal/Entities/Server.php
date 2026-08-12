<?php

namespace Bitrix\Superset\Internal\Entities;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Type\DateTime;

final class Server implements EntityInterface
{
	private ?int $id = null;
	private ?string $host = null;
	private ?string $accessPassword = null;
	private ?string $instanceKey = null;
	private ?string $instanceUsername = null;
	private ?string $token = null;
	private ?string $refreshToken = null;
	private ?string $startJobId = null;
	private int $accountId = 0;
	private ?int $version = null;
	private bool $isPortalIdVerified = false;
	private ?string $portalId = null;
	private ?string $portalUrl = null;
	private ?string $jwtSecret = null;
	private ?DateTime $dateStartAttempt = null;

	/** @var \Bitrix\MicroService\Entity\Account|null */
	private ?object $account = null;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getHost(): ?string
	{
		return $this->host;
	}

	public function setHost(?string $host): self
	{
		$this->host = $host;

		return $this;
	}

	public function unsetHost(): self
	{
		$this->host = null;

		return $this;
	}

	public function getAccessPassword(): ?string
	{
		return $this->accessPassword;
	}

	public function setAccessPassword(?string $accessPassword): self
	{
		$this->accessPassword = $accessPassword;

		return $this;
	}

	public function unsetAccessPassword(): self
	{
		$this->accessPassword = null;

		return $this;
	}

	public function getInstanceKey(): ?string
	{
		return $this->instanceKey;
	}

	public function setInstanceKey(?string $instanceKey): self
	{
		$this->instanceKey = $instanceKey;

		return $this;
	}

	public function unsetInstanceKey(): self
	{
		$this->instanceKey = null;

		return $this;
	}

	public function getInstanceUsername(): ?string
	{
		return $this->instanceUsername;
	}

	public function setInstanceUsername(?string $instanceUsername): self
	{
		$this->instanceUsername = $instanceUsername;

		return $this;
	}

	public function unsetInstanceUsername(): self
	{
		$this->instanceUsername = null;

		return $this;
	}

	public function getToken(): ?string
	{
		return $this->token;
	}

	public function setToken(?string $token): self
	{
		$this->token = $token;

		return $this;
	}

	public function unsetToken(): self
	{
		$this->token = null;

		return $this;
	}

	public function getRefreshToken(): ?string
	{
		return $this->refreshToken;
	}

	public function setRefreshToken(?string $refreshToken): self
	{
		$this->refreshToken = $refreshToken;

		return $this;
	}

	public function unsetRefreshToken(): self
	{
		$this->refreshToken = null;

		return $this;
	}

	public function getStartJobId(): ?string
	{
		return $this->startJobId;
	}

	public function setStartJobId(?string $startJobId): self
	{
		$this->startJobId = $startJobId;

		return $this;
	}

	public function unsetStartJobId(): self
	{
		$this->startJobId = null;

		return $this;
	}

	public function getAccountId(): int
	{
		return $this->accountId;
	}

	public function setAccountId(int $accountId): self
	{
		$this->accountId = $accountId;

		return $this;
	}

	public function getVersion(): ?int
	{
		return $this->version;
	}

	public function setVersion(?int $version): self
	{
		$this->version = $version;

		return $this;
	}

	public function unsetVersion(): self
	{
		$this->version = null;

		return $this;
	}

	public function isPortalIdVerified(): bool
	{
		return $this->isPortalIdVerified;
	}

	public function setIsPortalIdVerified(bool $isPortalIdVerified): self
	{
		$this->isPortalIdVerified = $isPortalIdVerified;

		return $this;
	}

	public function getPortalId(): ?string
	{
		return $this->portalId;
	}

	public function setPortalId(?string $portalId): self
	{
		$this->portalId = $portalId;

		return $this;
	}

	public function unsetPortalId(): self
	{
		$this->portalId = null;

		return $this;
	}

	public function getPortalUrl(): string
	{
		if ($this->portalUrl !== null && $this->portalUrl !== '')
		{
			return $this->portalUrl;
		}

		if ($this->account && method_exists($this->account, 'getPortalUrl'))
		{
			return (string)$this->account->getPortalUrl();
		}

		return '';
	}

	public function setPortalUrl(?string $portalUrl): self
	{
		$this->portalUrl = $portalUrl;

		return $this;
	}

	public function unsetPortalUrl(): self
	{
		$this->portalUrl = null;

		return $this;
	}

	public function getJwtSecret(): ?string
	{
		return $this->jwtSecret;
	}

	public function setJwtSecret(?string $jwtSecret): self
	{
		$this->jwtSecret = $jwtSecret;

		return $this;
	}

	public function unsetJwtSecret(): self
	{
		$this->jwtSecret = null;

		return $this;
	}

	public function getDateStartAttempt(): ?DateTime
	{
		return $this->dateStartAttempt;
	}

	public function setDateStartAttempt(?DateTime $dateStartAttempt): self
	{
		$this->dateStartAttempt = $dateStartAttempt;

		return $this;
	}

	public function unsetDateStartAttempt(): self
	{
		$this->dateStartAttempt = null;

		return $this;
	}

	/** @param \Bitrix\MicroService\Entity\Account $account */
	public function setAccount(object $account): self
	{
		$this->account = $account;

		if (method_exists($account, 'getId'))
		{
			$this->setAccountId((int)$account->getId());
		}

		return $this;
	}

	/** @return \Bitrix\MicroService\Entity\Account|null */
	public function getAccount(): ?object
	{
		return $this->account;
	}

	public function clearSupersetInstance(): self
	{
		return $this
			->unsetInstanceKey()
			->unsetInstanceUsername()
			->unsetJwtSecret()
			->unsetHost();
	}

	public function getPortalToken(): string
	{
		if (($this->instanceKey ?? '') === '' || $this->id === null)
		{
			return '';
		}

		return (new Signer())->sign("{$this->instanceKey}_{$this->id}", 'supersetEndpoint');
	}

	public function isReady(): bool
	{
		return ($this->host ?? '') !== '';
	}

	public function canMakeStartupAttempt(): bool
	{
		if ($this->isReady())
		{
			return false;
		}

		if ($this->dateStartAttempt === null)
		{
			return true;
		}

		return $this->dateStartAttempt->add('2 hours') < (new DateTime());
	}

	public function isInstanceCreating(): bool
	{
		return ($this->host ?? '') === ''
			&& ($this->instanceKey ?? '') !== ''
			&& ($this->instanceUsername ?? '') !== '';
	}
}
