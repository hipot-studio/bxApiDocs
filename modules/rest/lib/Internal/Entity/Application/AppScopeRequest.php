<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

class AppScopeRequest implements EntityInterface
{
	private ?int $id = null;
	private ?DateTime $dateCreate = null;
	private ?AppScopeRequestState $currentState = null;
	/** @var AppScopeRequestState[] */
	private array $stateHistory = [];

	/**
	 * @param int $appId
	 * @param string[] $scopes ["main", "tasks.task.get", "crm.deal"]
	 * @param int|null $stateId AppScopeRequestState ID
	 */
	public function __construct(
		private int $appId,
		private array $scopes,
		private ?int $stateId = null,
	) {}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getAppId(): int
	{
		return $this->appId;
	}

	public function getScopes(): array
	{
		return $this->scopes;
	}

	public function setScopes(array $scopes): self
	{
		$this->scopes = $scopes;

		return $this;
	}

	public function getStateId(): ?int
	{
		return $this->stateId;
	}

	public function setStateId(?int $stateId): self
	{
		$this->stateId = $stateId;

		return $this;
	}

	public function getCurrentState(): ?AppScopeRequestState
	{
		return $this->currentState;
	}

	public function setCurrentState(?AppScopeRequestState $currentState): self
	{
		$this->currentState = $currentState;

		return $this;
	}

	/**
	 * @return AppScopeRequestState[]
	 */
	public function getStateHistory(): array
	{
		return $this->stateHistory;
	}

	/**
	 * @param AppScopeRequestState[] $stateHistory
	 */
	public function setStateHistory(array $stateHistory): self
	{
		$this->stateHistory = $stateHistory;

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
}
