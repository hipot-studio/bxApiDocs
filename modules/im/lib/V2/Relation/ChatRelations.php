<?php

namespace Bitrix\Im\V2\Relation;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Relation;
use Bitrix\Im\V2\RelationCollection;

class ChatRelations
{
	protected const NEED_ADDITIONAL_FILTER_BY_ACCESS = false;

	/**
	 * @var self[]
	 */
	private static array $instances = [];

	protected int $chatId;
	/**
	 * @var Relation[]
	 */
	private array $relationByUserId = [];
	private RelationCollection $fullRelations;
	private RelationCollection $rawFullRelations;

	private function __construct(int $chatId)
	{
		$this->chatId = $chatId;
	}

	protected function getChat(): Chat
	{
		return Chat::getInstance($this->chatId);
	}

	public static function getInstance(int $chatId): static
	{
		if (!isset(self::$instances[$chatId]) || !(self::$instances[$chatId] instanceof static))
		{
			self::$instances[$chatId] = new static($chatId);
		}

		return self::$instances[$chatId];
	}

	public static function cleanInstance(int $chatId): void
	{
		unset(self::$instances[$chatId]);
	}

	public function forceRelations(RelationCollection $relations): void
	{
		$this->cleanCache();
		$this->fullRelations = $relations;
	}

	public function filterUserIdsByAccess(array $userIds): array
	{
		$chat = $this->getChat();
		if (!$chat->requiresParentMembership())
		{
			return $userIds;
		}

		$parentChat = $chat->getParentChat();
		if ($parentChat === null)
		{
			return $userIds;
		}

		// preload
		$parentChat->getRelationsByUserIds($userIds);

		return array_values(array_filter(
			$userIds,
			static fn (int $userId): bool => $parentChat->checkAccess($userId)->isSuccess(),
		));
	}

	protected function filterRelationsByAccess(RelationCollection $relations): RelationCollection
	{
		$chat = $this->getChat();
		$needsParentAccess = $chat->hasParent() && $chat->requiresParentMembership();

		if (!static::NEED_ADDITIONAL_FILTER_BY_ACCESS && !$needsParentAccess)
		{
			return $relations;
		}

		$allUserIds = $relations->getUserIds();
		$usersWithAccess = $this->filterUserIdsByAccess($allUserIds);

		$filtered = $relations->filter(fn (Relation $relation) => in_array($relation->getUserId(), $usersWithAccess, true));

		$this->markRemovedForDeletion(
			$this->filterUserIdsForCleanup(array_diff($allUserIds, $usersWithAccess))
		);

		return $filtered;
	}

	/**
	 * Excludes soft denials (e.g. tariff restriction) — they should not trigger relation cleanup.
	 *
	 * @param int[] $deniedUserIds
	 * @return int[]
	 */
	protected function filterUserIdsForCleanup(array $deniedUserIds): array
	{
		$parentChat = $this->getChat()->getParentChat();
		if ($parentChat === null)
		{
			return $deniedUserIds;
		}

		return array_values(array_filter(
			$deniedUserIds,
			static fn (int $userId): bool => $parentChat->checkAccess($userId)->getError()?->revokeAccess !== false,
		));
	}

	protected function markRemovedForDeletion(array $removedUserIds): void
	{
		$cleaner = LazyCleaner::getInstance();
		foreach ($removedUserIds as $userId)
		{
			if (!$cleaner->markForDeletion($this->chatId, $userId))
			{
				break;
			}
		}
	}

	public function preloadUserRelation(int $userId, ?Relation $relation): void
	{
		$this->relationByUserId[$userId] = $relation ?? false;
	}

	public function get(): RelationCollection
	{
		if (!isset($this->fullRelations))
		{
			$this->fullRelations = $this->loadFullRelations();
		}

		return $this->fullRelations;
	}

	public function getRawFullRelations(): RelationCollection
	{
		$this->rawFullRelations ??= RelationCollection::find(['CHAT_ID' => $this->chatId]);
		$this->mergeWithExistingRelations($this->rawFullRelations);

		return $this->rawFullRelations;
	}

	protected function loadFullRelations(): RelationCollection
	{
		$fullRelations = $this->filterRelationsByAccess($this->getRawFullRelations());
		$this->mergeWithExistingRelations($fullRelations);

		return $fullRelations;
	}

	protected function mergeWithExistingRelations(RelationCollection $relations): void
	{
		foreach ($this->relationByUserId as $userId => $relation)
		{
			if ($relation && $relations->getByUserId($userId, $this->chatId))
			{
				$relations->add($relation);
			}
		}
	}

	public function getManagerOnly(): RelationCollection
	{
		if (isset($this->fullRelations))
		{
			return $this->fullRelations->filter(fn (Relation $relation) => $relation->getManager());
		}

		return $this->loadManagersOnly();
	}

	protected function loadManagersOnly(): RelationCollection
	{
		return $this->filterRelationsByAccess(RelationCollection::find(['CHAT_ID' => $this->chatId, 'MANAGER' => 'Y']));
	}

	public function getByUserId(int $userId): ?Relation
	{
		if ($userId <= 0)
		{
			return null;
		}

		if (isset($this->relationByUserId[$userId]))
		{
			return $this->relationByUserId[$userId] ?: null;
		}

		if (isset($this->fullRelations))
		{
			return $this->fullRelations->getByUserId($userId, $this->chatId);
		}

		$relations = $this->loadByUserId($userId);
		$this->relationByUserId[$userId] = $relations->getByUserId($userId, $this->chatId) ?? false;

		return $this->relationByUserId[$userId] ?: null;
	}

	protected function loadByUserId(int $userId): RelationCollection
	{
		return $this->filterRelationsByAccess(RelationCollection::find(['CHAT_ID' => $this->chatId, 'USER_ID' => $userId]));
	}

	public function getByUserIds(array $userIds): RelationCollection
	{
		$userIds = Normalizer::toUniquePositiveIntegers($userIds);
		if (empty($userIds))
		{
			return new RelationCollection();
		}

		if (isset($this->fullRelations))
		{
			return $this->fullRelations->filter(fn (Relation $relation) => in_array($relation->getUserId(), $userIds, true));
		}

		return $this->loadByUserIds($userIds);
	}

	protected function loadByUserIds(array $userIds): RelationCollection
	{
		if (empty($userIds))
		{
			return new RelationCollection();
		}

		$resultCollection = new RelationCollection();
		$userIdsToLoad = [];
		foreach ($userIds as $userId)
		{
			$relation = $this->relationByUserId[$userId] ?? null;
			if ($relation === null)
			{
				$userIdsToLoad[$userId] = $userId;
			}
			elseif ($relation instanceof Relation)
			{
				$resultCollection->add($relation);
			}
		}

		if (!empty($userIdsToLoad))
		{
			$rawRelations = RelationCollection::find(['CHAT_ID' => $this->chatId, 'USER_ID' => $userIdsToLoad]);
			$loadedRelations = $this->filterRelationsByAccess($rawRelations);
			$this->fillRelationByUserId($loadedRelations);
			$resultCollection->mergeRegistry($loadedRelations);
		}

		foreach ($userIdsToLoad as $userId)
		{
			$this->relationByUserId[$userId] ??= false;
		}

		return $resultCollection;
	}

	protected function fillRelationByUserId(RelationCollection $relations): void
	{
		foreach ($relations as $relation)
		{
			$this->relationByUserId[$relation->getUserId()] = $relation;
		}
	}

	public function getByReason(Reason $reason): RelationCollection
	{
		if (isset($this->fullRelations))
		{
			return $this->fullRelations->filter(fn (Relation $relation) => $relation->getReason() === $reason);
		}

		return $this->loadByReason($reason);
	}

	protected function loadByReason(Reason $reason): RelationCollection
	{
		return $this->filterRelationsByAccess(RelationCollection::find(['CHAT_ID' => $this->chatId, 'REASON' => $reason->value]));
	}

	public function cleanCache(): void
	{
		unset($this->fullRelations, $this->rawFullRelations);
		$this->relationByUserId = [];
	}

	public static function cleanAllCache(): void
	{
		self::$instances = [];
	}

	public function onAfterRelationAdd(array $usersToAdd): void
	{
		//TODO: change to update cache for optimization
		$this->cleanCache();
	}

	public function onAfterRelationDelete(int $deletedUserId): void
	{
		$this->fullRelations->onAfterRelationDelete($this->chatId, $deletedUserId);
		$this->rawFullRelations->onAfterRelationDelete($this->chatId, $deletedUserId);
		unset($this->relationByUserId[$deletedUserId]);
	}

	public function onAfterMembersChange(): void
	{
		if (isset($this->fullRelations))
		{
			$this->fullRelations->clearActiveMemberCache();
		}
		if (isset($this->rawFullRelations))
		{
			$this->rawFullRelations->clearActiveMemberCache();
		}
	}

	public function getUserCount(): int
	{
		$fullRelations = $this->get();

		$count = 0;
		foreach ($fullRelations as $relation)
		{
			if (!$relation->isHidden() && $relation->getUser()->isActive())
			{
				$count++;
			}
		}

		return $count;
	}
}
