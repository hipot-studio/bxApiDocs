<?php

namespace Bitrix\Superset\Internal\Entities;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

final class User implements EntityInterface
{
	private ?int $id = null;
	private string $login = '';
	private ?string $accessPassword = null;
	private int $serverId = 0;
	private ?DateTime $created = null;
	private ?DateTime $updated = null;
	private int $externalId = 0;
	private string $clientId = '';

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getLogin(): string
	{
		return $this->login;
	}

	public function setLogin(string $login): self
	{
		$this->login = $login;

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

	public function getServerId(): int
	{
		return $this->serverId;
	}

	public function setServerId(int $serverId): self
	{
		$this->serverId = $serverId;

		return $this;
	}

	public function getCreated(): ?DateTime
	{
		return $this->created;
	}

	public function setCreated(?DateTime $created): self
	{
		$this->created = $created;

		return $this;
	}

	public function getUpdated(): ?DateTime
	{
		return $this->updated;
	}

	public function setUpdated(?DateTime $updated): self
	{
		$this->updated = $updated;

		return $this;
	}

	public function getExternalId(): int
	{
		return $this->externalId;
	}

	public function setExternalId(int $externalId): self
	{
		$this->externalId = $externalId;

		return $this;
	}

	public function getClientId(): string
	{
		return $this->clientId;
	}

	public function setClientId(string $clientId): self
	{
		$this->clientId = $clientId;

		return $this;
	}

	public function unsetClientId(): self
	{
		$this->clientId = '';

		return $this;
	}
}
