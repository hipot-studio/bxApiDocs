<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Updater\Delete;

class AudienceStep
{
	private ?int $userId = null;
	private ?array $userIds = null;
	private ?array $affectedUserIds = null;
	private bool $forAll = false;

	public function __construct(
		private readonly ScopeStep $scope
	) {}

	public function forUser(int $userId): Executor
	{
		$this->userId = $userId;
		$this->userIds = null;
		$this->forAll = false;
		return new Executor($this->scope, $this);
	}

	/**
	 * A bounded group of users, for the walks that take a chat apart member by member: the statement
	 * keeps to the users given, and only the users whose rows were really found lose their cache.
	 *
	 * @param int[] $userIds
	 */
	public function forUsers(array $userIds): Executor
	{
		$this->userId = null;
		$this->userIds = array_values(array_unique(array_map('intval', $userIds)));
		$this->forAll = false;
		return new Executor($this->scope, $this);
	}

	public function forAllUsers(?array $affectedUserIds = null): Executor
	{
		$this->userId = null;
		$this->userIds = null;
		$this->forAll = true;
		$this->affectedUserIds = $affectedUserIds;
		return new Executor($this->scope, $this);
	}

	public function getUserId(): ?int
	{
		return $this->userId;
	}

	/**
	 * @return int[]|null
	 */
	public function getUserIds(): ?array
	{
		return $this->userIds;
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
