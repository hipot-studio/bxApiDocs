<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control\Handler\Update;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
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

		$addResult = $this->addMembersByCommand($command);
		$deleteResult = $this->deleteMembersByCommand($command, $entityAfter);

		return $handlerResult->merge($addResult)->merge($deleteResult);
	}

	protected function addMembersByCommand(CollabUpdateCommand $command): HandlerResult
	{
		$handlerResult = new HandlerResult();

		$addMembers = $command->getAddMembers();
		if (empty($addMembers))
		{
			return $handlerResult;
		}

		[$userCodes, $departmentCodes] = $this->splitAccessCodes($addMembers);

		if (!empty($departmentCodes))
		{
			Container::getInstance()
				->getStructureRelationService()
				->linkDepartments($departmentCodes, $command->getId());
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

		ActionMessageBuffer::getInstance()
			->put(ActionType::AddUser, $command->getId(), $command->getInitiatorId(), $employeeIds, $actionParameters)
			->put(ActionType::AddGuest, $command->getId(), $command->getInitiatorId(), $guestIds, $actionParameters)
			->put(ActionType::AddBot, $command->getId(), $command->getInitiatorId(), $botIds, $actionParameters)
		;

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

		[$userCodes, $departmentCodes] = $this->splitAccessCodes($deleteMembers);

		if (!empty($departmentCodes))
		{
			Container::getInstance()
				->getStructureRelationService()
				->unlinkDepartments($departmentCodes, $command->getId(), $command->getInitiatorId());
		}

		if (empty($userCodes))
		{
			return $handlerResult;
		}

		$delete = (new AccessCodeConverter(...$userCodes))
			->getAccessCodeIdList()
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

		ActionMessageFactory::getInstance()
			->getActionMessage(ActionType::ExcludeUser, $command->getId(), $command->getInitiatorId())
			->send($delete)
		;

		$writeToLogResult = $this->writeDeleteMemberLog($delete, $entityAfter, $command->getInitiatorId());

		return $handlerResult->merge($writeToLogResult);
	}
}
