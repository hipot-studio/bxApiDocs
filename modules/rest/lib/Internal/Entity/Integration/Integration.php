<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Integration;

use Bitrix\Main\Entity\EntityInterface;

class Integration implements EntityInterface
{
	private ?int $id = null;

	public function __construct(
		private int $userId,
		private string $elementCode,
		private string $title,
		private ?int $passwordId = null,
		private ?int $appId = null,
		private array $scope = [],
		private ?array $query = null,
		private array $outgoingEvents = [],
		private bool $outgoingNeeded = false,
		private ?string $outgoingHandlerUrl = null,
		private bool $widgetNeeded = false,
		private ?string $widgetHandlerUrl = null,
		private array $widgetList = [],
		private ?string $applicationToken = null,
		private bool $applicationNeeded = false,
		private bool $applicationOnlyApi = false,
		private ?int $botId = null,
		private ?string $botHandlerUrl = null,
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

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function setUserId(int $userId): self
	{
		$this->userId = $userId;

		return $this;
	}

	public function getElementCode(): string
	{
		return $this->elementCode;
	}

	public function setElementCode(string $elementCode): self
	{
		$this->elementCode = $elementCode;

		return $this;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function setTitle(string $title): self
	{
		$this->title = $title;

		return $this;
	}

	public function getPasswordId(): ?int
	{
		return $this->passwordId;
	}

	public function setPasswordId(?int $passwordId): self
	{
		$this->passwordId = $passwordId;

		return $this;
	}

	public function getAppId(): ?int
	{
		return $this->appId;
	}

	public function setAppId(?int $appId): self
	{
		$this->appId = $appId;

		return $this;
	}

	public function getScope(): array
	{
		return $this->scope;
	}

	public function setScope(array $scope): self
	{
		$this->scope = $scope;

		return $this;
	}

	public function getQuery(): ?array
	{
		return $this->query;
	}

	public function setQuery(?array $query): self
	{
		$this->query = $query;

		return $this;
	}

	public function getOutgoingEvents(): array
	{
		return $this->outgoingEvents;
	}

	public function setOutgoingEvents(array $outgoingEvents): self
	{
		$this->outgoingEvents = $outgoingEvents;

		return $this;
	}

	public function isOutgoingNeeded(): bool
	{
		return $this->outgoingNeeded;
	}

	public function setOutgoingNeeded(bool $outgoingNeeded): self
	{
		$this->outgoingNeeded = $outgoingNeeded;

		return $this;
	}

	public function getOutgoingHandlerUrl(): ?string
	{
		return $this->outgoingHandlerUrl;
	}

	public function setOutgoingHandlerUrl(?string $outgoingHandlerUrl): self
	{
		$this->outgoingHandlerUrl = $outgoingHandlerUrl;

		return $this;
	}

	public function isWidgetNeeded(): bool
	{
		return $this->widgetNeeded;
	}

	public function setWidgetNeeded(bool $widgetNeeded): self
	{
		$this->widgetNeeded = $widgetNeeded;

		return $this;
	}

	public function getWidgetHandlerUrl(): ?string
	{
		return $this->widgetHandlerUrl;
	}

	public function setWidgetHandlerUrl(?string $widgetHandlerUrl): self
	{
		$this->widgetHandlerUrl = $widgetHandlerUrl;

		return $this;
	}

	public function getWidgetList(): array
	{
		return $this->widgetList;
	}

	public function setWidgetList(array $widgetList): self
	{
		$this->widgetList = $widgetList;

		return $this;
	}

	public function getApplicationToken(): ?string
	{
		return $this->applicationToken;
	}

	public function setApplicationToken(?string $applicationToken): self
	{
		$this->applicationToken = $applicationToken;

		return $this;
	}

	public function isApplicationNeeded(): bool
	{
		return $this->applicationNeeded;
	}

	public function setApplicationNeeded(bool $applicationNeeded): self
	{
		$this->applicationNeeded = $applicationNeeded;

		return $this;
	}

	public function isApplicationOnlyApi(): bool
	{
		return $this->applicationOnlyApi;
	}

	public function setApplicationOnlyApi(bool $applicationOnlyApi): self
	{
		$this->applicationOnlyApi = $applicationOnlyApi;

		return $this;
	}

	public function getBotId(): ?int
	{
		return $this->botId;
	}

	public function setBotId(?int $botId): self
	{
		$this->botId = $botId;

		return $this;
	}

	public function getBotHandlerUrl(): ?string
	{
		return $this->botHandlerUrl;
	}

	public function setBotHandlerUrl(?string $botHandlerUrl): self
	{
		$this->botHandlerUrl = $botHandlerUrl;

		return $this;
	}
}
