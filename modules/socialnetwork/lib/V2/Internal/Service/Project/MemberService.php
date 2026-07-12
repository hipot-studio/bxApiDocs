<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Socialnetwork\Collab\Control\CollabService;
use Bitrix\Socialnetwork\Collab\Control\Command\CollabUpdateCommand;
use Bitrix\Socialnetwork\Collab\Control\Member\CollabMemberFacade;
use Bitrix\Socialnetwork\Control\Member\Command\MembersCommand;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Exceptions\MemberCommandException;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\ChatMemberService;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectMemberRepositoryInterface;

class MemberService
{
	public function __construct(
		private readonly CollabMemberFacade $collabMemberFacade,
		private readonly MemberEntityMapper $memberMapper,
		private readonly CollabService $collabService,
		private readonly ProjectMemberRepositoryInterface $memberRepository,
		private readonly ChatMemberService $chatMemberService,
	)
	{
	}

	/**
	 * @throws MemberCommandException
	 */
	public function addMembers(
		int $projectId,
		MemberEntityCollection $members,
		int $userId,
		?string $initiatedByType = null,
	): void
	{
		$command = $this->buildMembersCommand($projectId, $members, $userId);

		if ($command === null)
		{
			return;
		}

		if ($initiatedByType !== null)
		{
			$command->setInitiatedByType($initiatedByType);
		}

		$result = $this->collabMemberFacade->add($command);

		if (!$result->isSuccess())
		{
			$errorMessages = array_map(static fn($e) => $e->getMessage(), $result->getErrors());

			throw new MemberCommandException(implode('; ', $errorMessages));
		}
	}

	/**
	 * @throws MemberCommandException
	 */
	public function deleteMembers(int $projectId, MemberEntityCollection $members, int $userId): void
	{
		$command = $this->buildMembersCommand($projectId, $members, $userId);

		if ($command === null)
		{
			return;
		}

		$result = $this->collabMemberFacade->delete($command);

		if (!$result->isSuccess())
		{
			$errorMessages = array_map(static fn($e) => $e->getMessage(), $result->getErrors());

			throw new MemberCommandException(implode('; ', $errorMessages));
		}
	}

	/**
	 * @throws MemberCommandException
	 */
	public function addModerators(int $projectId, MemberEntityCollection $moderators, int $userId): void
	{
		$command = $this->buildMembersCommand($projectId, $moderators, $userId);

		if ($command === null)
		{
			return;
		}

		$result = $this->collabMemberFacade->addModerators($command);

		if (!$result->isSuccess())
		{
			$errorMessages = array_map(static fn($e) => $e->getMessage(), $result->getErrors());

			throw new MemberCommandException(implode('; ', $errorMessages));
		}
	}

	/**
	 * @throws MemberCommandException
	 */
	public function deleteModerators(int $projectId, MemberEntityCollection $moderators, int $userId): void
	{
		$command = $this->buildMembersCommand($projectId, $moderators, $userId);

		if ($command === null)
		{
			return;
		}

		$result = $this->collabMemberFacade->deleteModerators($command);

		if (!$result->isSuccess())
		{
			$errorMessages = array_map(static fn($e) => $e->getMessage(), $result->getErrors());

			throw new MemberCommandException(implode('; ', $errorMessages));
		}
	}

	/**
	 * @throws MemberCommandException
	 */
	public function inviteMembers(int $projectId, MemberEntityCollection $members, int $userId): void
	{
		$command = $this->buildMembersCommand($projectId, $members, $userId);

		if ($command === null)
		{
			return;
		}

		$result = $this->collabMemberFacade->invite($command);

		if (!$result->isSuccess())
		{
			$errorMessages = array_map(static fn($e) => $e->getMessage(), $result->getErrors());

			throw new MemberCommandException(implode('; ', $errorMessages));
		}
	}

	/**
	 * @throws MemberCommandException
	 */
	public function setOwner(int $projectId, int $ownerId, int $userId): void
	{
		$updateCommand =
			(new CollabUpdateCommand())
				->setId($projectId)
				->setOwnerId($ownerId)
				->setInitiatorId($userId)
		;

		$result = $this->collabService->update($updateCommand);

		if (!$result->isSuccess())
		{
			$errorMessages = array_map(static fn($e) => $e->getMessage(), $result->getErrors());

			throw new MemberCommandException(implode('; ', $errorMessages));
		}
	}

	/**
	 * Marks existing project members as structure-initiated:
	 * sets INITIATED_BY_TYPE/AUTO_MEMBER in socialnetwork and REASON in im
	 * (im's addUsers only updates REASON for an already existing chat member).
	 *
	 * @param int[] $userIds
	 */
	public function markMembersAsStructureInitiated(int $projectId, array $userIds): void
	{
		if (empty($userIds))
		{
			return;
		}

		$structureInitiatedUserIds = $this->memberRepository->markMembersAsStructureInitiated($projectId, $userIds);
		if (empty($structureInitiatedUserIds))
		{
			return;
		}

		$this->chatMemberService->addUsersAsStructureMembers($projectId, $structureInitiatedUserIds);
	}

	/**
	 * @throws MemberCommandException
	 */
	private function buildMembersCommand(int $projectId, MemberEntityCollection $members, int $userId): ?MembersCommand
	{
		if ($members->isEmpty())
		{
			return null;
		}

		$accessCodes = $this->memberMapper->toAccessCodes($members);

		if (empty($accessCodes))
		{
			throw new MemberCommandException('No valid members to process');
		}

		return
			(new MembersCommand())
				->setGroupId($projectId)
				->setMembers($accessCodes)
				->setInitiatorId($userId)
		;
	}
}
