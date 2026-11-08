<?php

namespace Bitrix\Rest\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;

class Event implements EntityInterface
{
	public const VERSION_REST_V1 = 1;
	public const VERSION_REST_V3 = 3;

	public function __construct(
		private ?int $id,
		private ?int $appId,
		private string $name,
		private string $handler,
		private ?int $userId,
		private ?string $title,
		private ?string $comment,
		private ?string $applicationToken,
		private ?string $connectorId,
		private ?int $integrationId,
		private ?array $options,
		private ?int $version,
		private ?array $jsonOptions,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): Event
	{
		$this->id = $id;

		return $this;
	}

	public function getAppId(): ?int
	{
		return $this->appId;
	}

	public function setAppId(?int $appId): Event
	{
		$this->appId = $appId;

		return $this;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function setName(string $name): Event
	{
		$this->name = $name;

		return $this;
	}

	public function getHandler(): string
	{
		return $this->handler;
	}

	public function setHandler(string $handler): Event
	{
		$this->handler = $handler;

		return $this;
	}

	public function getUserId(): ?int
	{
		return $this->userId;
	}

	public function setUserId(?int $userId): Event
	{
		$this->userId = $userId;

		return $this;
	}

	public function getTitle(): ?string
	{
		return $this->title;
	}

	public function setTitle(?string $title): Event
	{
		$this->title = $title;

		return $this;
	}

	public function getComment(): ?string
	{
		return $this->comment;
	}

	public function setComment(?string $comment): Event
	{
		$this->comment = $comment;

		return $this;
	}

	public function getApplicationToken(): ?string
	{
		return $this->applicationToken;
	}

	public function setApplicationToken(?string $applicationToken): Event
	{
		$this->applicationToken = $applicationToken;

		return $this;
	}

	public function getConnectorId(): ?string
	{
		return $this->connectorId;
	}

	public function setConnectorId(?string $connectorId): Event
	{
		$this->connectorId = $connectorId;

		return $this;
	}

	public function getIntegrationId(): ?int
	{
		return $this->integrationId;
	}

	public function setIntegrationId(?int $integrationId): Event
	{
		$this->integrationId = $integrationId;

		return $this;
	}

	public function getOptions(): ?array
	{
		return $this->options;
	}

	public function setOptions(?array $options): Event
	{
		$this->options = $options;

		return $this;
	}

	public function getVersion(): ?int
	{
		return $this->version;
	}

	public function setVersion(?int $version): Event
	{
		$this->version = $version;

		return $this;
	}

	public function getJsonOptions(): ?array
	{
		return $this->jsonOptions;
	}

	public function setJsonOptions(?array $jsonOptions): Event
	{
		$this->jsonOptions = $jsonOptions;

		return $this;
	}
}