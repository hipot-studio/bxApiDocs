<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control\Handler\Update;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Collab\Control\Command\CollabUpdateCommand;
use Bitrix\Socialnetwork\Collab\Control\Handler\Trait\SplitAccessCodesTrait;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageBuffer;
use Bitrix\Socialnetwork\Provider\EmployeeProvider;
use Bitrix\Socialnetwork\Collab\Control\Handler\Trait\AddMemberLogTrait;
use Bitrix\Socialnetwork\Collab\Control\Handler\Trait\DeleteMemberLogTrait;
use Bitrix\Socialnetwork\Control\Member\Trait\AddMemberTrait;
use Bitrix\Socialnetwork\Control\Member\Trait\GetMembersTrait;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageFactory;
use Bitrix\Socialnetwork\Control\Command\UpdateCommand;
use Bitrix\Socialnetwork\Control\Handler\HandlerResult;
use Bitrix\Socialnetwork\Control\Handler\Update\UpdateHandlerInterface;
use Bitrix\Socialnetwork\Integration\HumanResources\AccessCodeConverter;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\MemberService;

class UpdateMemberHandler implements UpdateHandlerInterface
{
	use AddMemberTrait;
	use GetMembersTrait;
	use AddMemberLogTrait;
	use DeleteMemberLogTrait;
	use SplitAccessCodesTrait;

	public function update(UpdateCommand $command, Workgroup $entityBefore, Workgroup $entityAfter): HandlerResult
	{
		$handlerResult = new HandlerResult();

		if (!$command instanceof CollabUpdateCommand)
		{
			$handlerResult->addError(new Error('Unexpected command type'));

			return $handlerResult;
		}

		$accessResult = $this->validateNodesAccess($command);
		if (!$accessResult->isSuccess())
		{
			return $handlerResult->merge($accessResult);
		}

		$addResult = $this->addMembersByCommand($command);
		$deleteResult = $this->deleteMembersByCommand($command, $entityAfter);

		return $handlerResult->merge($addResult)->merge($deleteResult);
	}

	/**
	 * Both halves of the save are judged before either of them writes: a node the initiator may not see refuses
	 * the whole edit, so a project is never left with the additions of a save whose removals were refused, or
	 * the other way round.
	 */
	protected function validateNodesAccess(CollabUpdateCommand $command): Result
	{
		[, $addStructureCodes] = $this->splitAccessCodes($command->getAddMembers() ?? []);
		[, $deleteStructureCodes] = $this->splitAccessCodes($command->getDeleteMembers() ?? []);

		return Container::getInstance()
			->getStructureRelationService()
			->validateNodesAccess([...$addStructureCodes, ...$deleteStructureCodes], $command->getInitiatorId())
		;
	}

	protected function addMembersByCommand(CollabUpdateCommand $command): HandlerResult
	{
		$handlerResult = new HandlerResult();

		$addMembers = $command->getAddMembers();
		if (empty($addMembers))
		{
			return $handlerResult;
		}

		[$userCodes, $structureCodes] = $this->splitAccessCodes($addMembers);

		if (!empty($structureCodes))
		{
			$linkResult = Container::getInstance()
				->getStructureRelationService()
				->linkNodes($structureCodes, $command->getId(), $command->getInitiatorId());

			if (!$linkResult->isSuccess())
			{
				return $handlerResult->merge($linkResult);
			}
		}

		if (empty($userCodes))
		{
			return $handlerResult;
		}

		$addMembersByCommand = (new AccessCodeConverter(...$userCodes))
			->getAccessCodeIdList()
		;

		$existingMemberIds = $this->getMemberIds(
			$command->getId(),
			excludeRole: UserToGroupTable::ROLE_REQUEST,
		);

		$membersToAdd = array_diff($addMembersByCommand, $existingMemberIds);

		if ($command->getInitiatedByType() === UserToGroupTable::INITIATED_BY_STRUCTURE)
		{
			$alreadyAddedMembers = array_intersect($addMembersByCommand, $existingMemberIds);

			Container::getInstance()
				->get(MemberService::class)
				->markMembersAsStructureInitiated($command->getId(), $alreadyAddedMembers)
			;
		}

		$handlerResult = $this->addMembers(
				$command->getId(),
				$command->getInitiatorId(),
				UserToGroupTable::ROLE_USER,
				$command->getInitiatedByType(),
			...$membersToAdd,
		);

		if (!$handlerResult->isSuccess())
		{
			return $handlerResult;
		}

		if (Option::get('socialnetwork', 'temp_anyway_add_chat_member', 'N') === 'Y')
		{
			[$employeeIds, $guestIds, $botIds] = EmployeeProvider::getInstance()->splitIntoEmployeesGuestsAndBots($addMembersByCommand);
		}
		else
		{
			[$employeeIds, $guestIds, $botIds] = EmployeeProvider::getInstance()->splitIntoEmployeesGuestsAndBots($membersToAdd);
		}

		$actionParameters = ['initiatedByType' => $command->getInitiatedByType()];
		$botActionParameters = $actionParameters;

		if ($command->getAddBotSilently())
		{
			$botActionParameters['withoutMessage'] = true;
		}

		$sharingLinkAuthorId = $command->getSharingLinkAuthorId();

		if ($sharingLinkAuthorId > 0)
		{
			$sharingLinkParameters = array_merge($actionParameters, [
				'authorId' => $sharingLinkAuthorId,
				'initiatedByType' => $command->getInitiatedByType(),
			]);

			ActionMessageBuffer::getInstance()
				->put(ActionType::JoinBySharingLink, $command->getId(), $command->getInitiatorId(), $employeeIds, $sharingLinkParameters)
				->put(ActionType::JoinBySharingLink, $command->getId(), $command->getInitiatorId(), $guestIds, $sharingLinkParameters)
				->put(ActionType::AddBot, $command->getId(), $command->getInitiatorId(), $botIds, $botActionParameters)
			;
		}
		else
		{
			ActionMessageBuffer::getInstance()
				->put(ActionType::AddUser, $command->getId(), $command->getInitiatorId(), $employeeIds, $actionParameters)
				->put(ActionType::AddGuest, $command->getId(), $command->getInitiatorId(), $guestIds, $actionParameters)
				->put(ActionType::AddBot, $command->getId(), $command->getInitiatorId(), $botIds, $botActionParameters)
			;
		}

		$writeToLogResult = $this->writeAddMemberLog(
			$membersToAdd,
			$command->getId(),
			$command->getInitiatorId(),
			UserToGroupTable::ROLE_USER
		);

		return $handlerResult->merge($writeToLogResult);
	}

	protected function deleteMembersByCommand(CollabUpdateCommand $command, Workgroup $entityAfter): HandlerResult
	{
		$handlerResult = new HandlerResult();

		$deleteMembers = $command->getDeleteMembers();
		if (empty($deleteMembers))
		{
			return $handlerResult;
		}

		[$userCodes, $structureCodes] = $this->splitAccessCodes($deleteMembers);

		if (!empty($structureCodes))
		{
			$unlinkResult = Container::getInstance()
				->getStructureRelationService()
				->unlinkNodes($structureCodes, $command->getId(), $command->getInitiatorId());

			if (!$unlinkResult->isSuccess())
			{
				return $handlerResult->merge($unlinkResult);
			}
		}

		if (empty($userCodes))
		{
			return $handlerResult;
		}

		$delete = (new AccessCodeConverter(...$userCodes))
			->getAccessCodeIdList()
		;

		// Capture structure-initiated members before deletion: their removal is announced project-wide by the
		// node message the humanresources EventHandler buffers, so they are excluded from the per-user
		// exclusion message below. Covers both removal paths that reach this handler:
		// chat-member sync (OnAfterUsersDeleteEvent) and the COLLAB-relation unlink (StructureSyncService).
		$structureInitiatedIds = Container::getInstance()
			->getProjectMemberRepository()
			->getStructureInitiatedMemberIds($command->getId(), $delete)
		;

		$handlerResult = $this->deleteMembers($command->getId(), ...$delete);

		if (!$handlerResult->isSuccess())
		{
			return $handlerResult;
		}

		if (in_array($command->getInitiatorId(), $delete, true))
		{
			ActionMessageFactory::getInstance()
				->getActionMessage(ActionType::LeaveUser, $command->getId(), $command->getInitiatorId())
				->send()
			;

			$delete = array_filter(
				$delete,
				static fn(int $userId): bool => $userId !== $command->getInitiatorId(),
			);
		}

		$excludeRecipients = array_values(array_diff($delete, $structureInitiatedIds));
		if (!empty($excludeRecipients))
		{
			if (!empty($structureCodes))
			{
				$buffer = ActionMessageBuffer::getInstance();
				$parameters = $buffer->areMemberMessagesSuppressed($command->getId())
					? [ActionMessageBuffer::WITHOUT_MESSAGE_PARAMETER => true]
					: []
				;
				$buffer->put(
					ActionType::ExcludeStructureNode,
					$command->getId(),
					$command->getInitiatorId(),
					$excludeRecipients,
					$parameters,
				);
			}
			else
			{
				ActionMessageFactory::getInstance()
					->getActionMessage(ActionType::ExcludeUser, $command->getId(), $command->getInitiatorId())
					->send($excludeRecipients)
				;
			}
		}

		$writeToLogResult = $this->writeDeleteMemberLog($delete, $entityAfter, $command->getInitiatorId());

		return $handlerResult->merge($writeToLogResult);
	}
}
