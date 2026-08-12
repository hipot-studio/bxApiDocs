<?php

declare(strict_types=1);

namespace Bitrix\Landing\Realtime;

final class Event
{
	private string $eventName;
	private string $entityType;
	private int $entityId;
	private string $action;
	private string $source;
	private int $initiatorUserId;
	private array $scope;
	private array $meta;

	public function __construct(
		string $eventName,
		string $entityType,
		int $entityId,
		string $action,
		string $source,
		int $initiatorUserId,
		array $scope = [],
		array $meta = []
	)
	{
		$this->eventName = $eventName;
		$this->entityType = $entityType;
		$this->entityId = $entityId;
		$this->action = $action;
		$this->source = $source;
		$this->initiatorUserId = $initiatorUserId;
		$this->scope = $scope;
		$this->meta = $meta;
	}

	public static function siteChanged(
		int $entityId,
		string $action,
		string $source,
		int $initiatorUserId,
		array $scope = [],
		array $meta = []
	): self
	{
		return new self(
			EventName::SiteChanged,
			EventEntityType::Site,
			$entityId,
			$action,
			$source,
			$initiatorUserId,
			$scope,
			$meta
		);
	}

	public static function blockChanged(
		int $entityId,
		string $action,
		string $source,
		int $initiatorUserId,
		array $scope = [],
		array $meta = []
	): self
	{
		return new self(
			EventName::BlockChanged,
			EventEntityType::Block,
			$entityId,
			$action,
			$source,
			$initiatorUserId,
			$scope,
			$meta
		);
	}

	public function getEventName(): string
	{
		return $this->eventName;
	}

	public function getEntityType(): string
	{
		return $this->entityType;
	}

	public function getEntityId(): int
	{
		return $this->entityId;
	}

	public function getAction(): string
	{
		return $this->action;
	}

	public function getSource(): string
	{
		return $this->source;
	}

	public function getInitiatorUserId(): int
	{
		return $this->initiatorUserId;
	}

	public function getScope(): array
	{
		return $this->scope;
	}

	public function getMeta(): array
	{
		return $this->meta;
	}

	public function withScope(array $scope): self
	{
		return new self(
			$this->eventName,
			$this->entityType,
			$this->entityId,
			$this->action,
			$this->source,
			$this->initiatorUserId,
			array_merge($this->scope, $scope),
			$this->meta
		);
	}

	public function withMeta(array $meta): self
	{
		return new self(
			$this->eventName,
			$this->entityType,
			$this->entityId,
			$this->action,
			$this->source,
			$this->initiatorUserId,
			$this->scope,
			array_merge($this->meta, $meta)
		);
	}
}
