<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\StructureSync;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\UI\EntitySelector\Converter;
use Bitrix\Socialnetwork\Collab\Control\Member\CollabMemberFacade;
use Bitrix\Socialnetwork\Control\Member\Command\MembersCommand;
use Bitrix\Socialnetwork\Helper\Workgroup;
use Bitrix\Socialnetwork\Item\UserToGroup;
use Bitrix\Socialnetwork\Update\WorkgroupDeptSync;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Service\StructureRelationService;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectMemberRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupRepository;
use Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\Async\AbstractStructureSyncMessage;
use Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\Async\LegacyDepartmentDeletedMessage;
use Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\Async\MemberAddedMessage;
use Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\Async\MemberDeletedMessage;
use Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\Async\RelationAddedMessage;
use Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\Async\RelationDeletedMessage;

class StructureSyncService
{
	private const BATCH_SIZE = 200;
	private const SYNC_MEMBER_LIMIT = 20;

	public function __construct(
		private readonly StructureRelationService $structureRelationService,
		private readonly ProjectMemberRepositoryInterface $memberRepository,
		private readonly CollabMemberFacade $memberFacade,
		private readonly WorkgroupRepository $workgroupRepository,
	)
	{
	}

	public function enqueueRelationAdded(
		int $nodeId,
		int $entityId,
		int $createdBy,
		bool $withChildNodes,
	): void
	{
		$this->enqueueSync(new RelationAddedMessage(
			nodeId: $nodeId,
			entityId: $entityId,
			createdBy: $createdBy,
			withChildNodes: $withChildNodes,
		));
	}

	public function enqueueRelationDeleted(
		int $nodeId,
		int $entityId,
		int $createdBy,
		bool $withChildNodes,
	): void
	{
		$this->enqueueSync(new RelationDeletedMessage(
			nodeId: $nodeId,
			entityId: $entityId,
			createdBy: $createdBy,
			withChildNodes: $withChildNodes,
		));
	}

	public function enqueueLegacyDepartmentDeleted(int $collabId, int $departmentId, int $initiatorId): void
	{
		$this->enqueueSync(new LegacyDepartmentDeletedMessage(
			collabId: $collabId,
			departmentId: $departmentId,
			initiatorId: $initiatorId,
		));
	}

	public function handleRelationAdded(
		int $nodeId,
		int $entityId,
		int $createdBy,
		bool $withChildNodes,
		int $offset = 0,
	): RelationSyncResult
	{
		if (!$this->memberRepository->isCollabExists($entityId))
		{
			return new RelationSyncResult();
		}

		$employeeIds = $this->structureRelationService->getEmployeeIds(
			$nodeId,
			$withChildNodes,
			$offset,
			self::BATCH_SIZE,
		);

		if (empty($employeeIds))
		{
			return new RelationSyncResult();
		}

		$convertedMembers = $this->convertUserIdsToFinderCodes($employeeIds);
		$result = $this->addMembers($entityId, $createdBy, $convertedMembers);

		$hasMore = count($employeeIds) === self::BATCH_SIZE;
		$syncResult = new RelationSyncResult(
			hasMore: $hasMore,
			nextOffset: $hasMore ? $offset + self::BATCH_SIZE : null,
		);
		$syncResult->addErrors($result->getErrors());

		return $syncResult;
	}

	public function handleMemberAdded(int $nodeId, int $userId): Result
	{
		$relations = $this->structureRelationService->getCollabRelations($nodeId);

		if (empty($relations))
		{
			return new Result();
		}

		$syncRelations = array_slice($relations, 0, self::SYNC_MEMBER_LIMIT);
		$result = $this->processMemberAdded($syncRelations, $userId);

		if (count($relations) > self::SYNC_MEMBER_LIMIT)
		{
			$this->enqueueSync(new MemberAddedMessage(
				nodeId: $nodeId,
				userId: $userId,
				offset: self::SYNC_MEMBER_LIMIT,
			));
		}

		return $result;
	}

	public function handleMemberAddedAsync(int $nodeId, int $userId, int $offset): Result
	{
		$relations = $this->structureRelationService->getCollabRelations($nodeId);
		$relationsToProcess = array_slice($relations, $offset);

		return $this->processMemberAdded($relationsToProcess, $userId);
	}

	public function handleRelationDeleted(
		int $nodeId,
		int $entityId,
		int $createdBy,
		bool $withChildNodes,
		int $offset = 0,
	): RelationSyncResult
	{
		if (!$this->memberRepository->isCollabExists($entityId))
		{
			return new RelationSyncResult();
		}

		$employeeIds = $this->structureRelationService->getEmployeeIds(
			$nodeId,
			$withChildNodes,
			$offset,
			self::BATCH_SIZE,
		);

		if (empty($employeeIds))
		{
			return new RelationSyncResult();
		}

		$result = $this->deleteStructureInitiatedMembers($entityId, $createdBy, $employeeIds);

		$hasMore = count($employeeIds) === self::BATCH_SIZE;
		$syncResult = new RelationSyncResult(
			hasMore: $hasMore,
			nextOffset: $hasMore ? $offset + self::BATCH_SIZE : null,
		);
		$syncResult->addErrors($result->getErrors());

		return $syncResult;
	}

	/**
	 * @throws ArgumentException
	 * @throws NotImplementedException
	 * @throws ObjectNotFoundException
	 */
	public function handleLegacyDepartmentDeleted(int $collabId, int $departmentId, int $initiatorId): Result
	{
		if (!$this->memberRepository->isCollabExists($collabId))
		{
			return new Result();
		}

		if ($this->workgroupRepository->hasLegacyDepartment($collabId, $departmentId))
		{
			Workgroup::disconnectDepartment(['groupId' => $collabId, 'departmentId' => $departmentId]);
		}

		$departmentSyncDiff = WorkgroupDeptSync::getUsers($collabId, useCache: false);

		$orphanedUserIds = $departmentSyncDiff['MINUS'] ?? [];
		Collection::normalizeArrayValuesByInt($orphanedUserIds, false);

		if (empty($orphanedUserIds))
		{
			return new Result();
		}

		// Moderators and owners must not be kicked out just because the department was unlinked
		$userIdsToPreserve = array_intersect(
			$orphanedUserIds,
			$this->memberRepository->getModeratorUserIds($collabId),
		);

		$this->detachFromAutoMembership($userIdsToPreserve, $departmentSyncDiff['OLD_RELATIONS'] ?? []);

		$userIdsToDelete = array_diff($orphanedUserIds, $userIdsToPreserve);

		return $this->deleteMembers($collabId, $initiatorId, $this->convertUserIdsToFinderCodes($userIdsToDelete));
	}

	public function handleMemberDeleted(int $nodeId, int $userId): Result
	{
		$relations = $this->structureRelationService->getCollabRelations($nodeId);

		if (empty($relations))
		{
			return new Result();
		}

		$syncRelations = array_slice($relations, 0, self::SYNC_MEMBER_LIMIT);
		$result = $this->processMemberDeleted($syncRelations, $userId);

		if (count($relations) > self::SYNC_MEMBER_LIMIT)
		{
			$this->enqueueSync(new MemberDeletedMessage(
				nodeId: $nodeId,
				userId: $userId,
				offset: self::SYNC_MEMBER_LIMIT,
			));
		}

		return $result;
	}

	public function handleMemberDeletedAsync(int $nodeId, int $userId, int $offset): Result
	{
		$relations = $this->structureRelationService->getCollabRelations($nodeId);
		$relationsToProcess = array_slice($relations, $offset);

		return $this->processMemberDeleted($relationsToProcess, $userId);
	}

	protected function enqueueSync(AbstractStructureSyncMessage $message): void
	{
		$message->sendToQueue();
	}

	private function detachFromAutoMembership(array $userIds, array $oldRelations): void
	{
		foreach ($userIds as $userId)
		{
			$relationId = (int)($oldRelations[$userId] ?? 0);
			if ($relationId <= 0)
			{
				continue;
			}

			UserToGroup::changeRelationAutoMembership([
				'RELATION_ID' => $relationId,
				'VALUE' => 'N',
			]);
		}
	}

	private function processMemberAdded(array $relations, int $userId): Result
	{
		$convertedMembers = $this->convertUserIdsToFinderCodes([$userId]);

		$result = new Result();
		foreach ($relations as $relation)
		{
			$result->addErrors(
				$this->addMembers($relation->entityId, $relation->createdBy, $convertedMembers)->getErrors()
			);
		}

		return $result;
	}

	private function processMemberDeleted(array $relations, int $userId): Result
	{
		$result = new Result();
		foreach ($relations as $relation)
		{
			$deleteResult = $this->deleteStructureInitiatedMembers(
				$relation->entityId,
				$relation->createdBy,
				[$userId],
			);

			$result->addErrors($deleteResult->getErrors());
		}

		return $result;
	}

	private function addMembers(int $groupId, int $initiatorId, array $convertedMembers): Result
	{
		$command = (new MembersCommand())
			->setMembers($convertedMembers)
			->setInitiatorId($initiatorId)
			->setGroupId($groupId)
			->setInitiatedByType(UserToGroupTable::INITIATED_BY_STRUCTURE)
		;

		return $this->memberFacade->add($command);
	}

	private function deleteStructureInitiatedMembers(int $groupId, int $initiatorId, array $employeeIds): Result
	{
		$filteredUsers = $this->structureRelationService->getUsersNotInOtherRelations(
			$groupId,
			$employeeIds,
		);

		$filteredUsers = $this->memberRepository->getStructureInitiatedMemberIds(
			$groupId,
			$filteredUsers,
		);

		return $this->deleteMembers($groupId, $initiatorId, $this->convertUserIdsToFinderCodes($filteredUsers));
	}

	private function deleteMembers(int $groupId, int $initiatorId, array $convertedMembers): Result
	{
		if (empty($convertedMembers))
		{
			return new Result();
		}

		$command =
			(new MembersCommand())
				->setMembers($convertedMembers)
				->setInitiatorId($initiatorId)
				->setGroupId($groupId)
		;

		return $this->memberFacade->delete($command);
	}

	/**
	 * @param int[] $userIds
	 * @return string[]
	 */
	private function convertUserIdsToFinderCodes(array $userIds): array
	{
		$users = array_map(
			static fn(int $userId): array => ['user', $userId],
			$userIds,
		);

		return Converter::convertToFinderCodes($users);
	}
}
