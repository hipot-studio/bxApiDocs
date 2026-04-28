<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Updater\Delete;

class AudienceStep
{
	private ?int $userId = null;
	private ?array $affectedUserIds = null;
	private bool $forAll = false;

	public function __construct(
		private readonly ScopeStep $scope
	) {}

	public function forUser(int $userId): Executor
	{
		$this->userId = $userId;
		$this->forAll = false;
		return new Executor($this->scope, $this);
	}

	public function forAllUsers(?array $affectedUserIds = null): Executor
	{
		$this->userId = null;
		$this->forAll = true;
		$this->affectedUserIds = $affectedUserIds;
		return new Executor($this->scope, $this);
	}

	public function getUserId(): ?int
	{
		return $this->userId;
	}

	public function isForAll(): bool
	{
		return $this->forAll;
	}

	public function getAffectedUserIds(): ?array
	{
		return $this->affectedUserIds;
	}
}
