<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control\Handler\Update;

use Bitrix\Socialnetwork\Collab\Activity\LastActivityTrigger;
use Bitrix\Socialnetwork\Collab\Control\Handler\Trait\AddMemberLogTrait;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageBuffer;
use Bitrix\Socialnetwork\Provider\EmployeeProvider;
use Bitrix\Socialnetwork\Control\Member\Trait\AddMemberTrait;
use Bitrix\Socialnetwork\Control\Member\Trait\GetMembersTrait;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\Control\Command\UpdateCommand;
use Bitrix\Socialnetwork\Control\Handler\HandlerResult;
use Bitrix\Socialnetwork\Control\Handler\Update\UpdateHandlerInterface;
use Bitrix\Socialnetwork\Integration\HumanResources\AccessCodeConverter;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\UserToGroupTable;

class UpdateInviteHandler implements UpdateHandlerInterface
{
	use GetMembersTrait;
	use AddMemberTrait;
	use AddMemberLogTrait;

	public function update(UpdateCommand $command, Workgroup $entityBefore, Workgroup $entityAfter): HandlerResult
	{
		$handlerResult = new HandlerResult();

		$invitedMembers = $command->getAddInvitedMembers();
		if (empty($invitedMembers))
		{
			return $handlerResult;
		}

		$membersByCommand = (new AccessCodeConverter(...$invitedMembers))
			->getUsers()
			->getUserIds()
		;

		$add = array_diff($membersByCommand, $this->getMemberIds($command->getId()));

		[$employeeIds, $guestIds, $botIds] = EmployeeProvider::getInstance()->splitIntoEmployeesGuestsAndBots($add);

		$nonBotIds = array_merge($employeeIds, $guestIds);

		$inviteResult = $this->inviteMembers(
			$command->getId(),
			$command->getInitiatorId(),
			...$nonBotIds,
		);

		$handlerResult->merge($inviteResult);

		$botResult = $this->addMembers(
			$command->getId(),
			$command->getInitiatorId(),
			UserToGroupTable::ROLE_USER,
			UserToGroupTable::INITIATED_BY_GROUP,
			...$botIds,
		);

		$handlerResult->merge($botResult);

		if (!$handlerResult->isSuccess())
		{
			return $handlerResult;
		}

		foreach ($add as $userId)
		{
			LastActivityTrigger::execute($userId, $command->getId());
		}

		$parameters = [
			'skipChat' => !$command->getInitiator()->isIntranet(),
		];

		ActionMessageBuffer::getInstance()
			->put(ActionType::InviteGuest, $command->getId(), $command->getInitiatorId(), $guestIds, $parameters)
			->put(ActionType::InviteUser, $command->getId(), $command->getInitiatorId(), $employeeIds, $parameters)
			->put(ActionType::AddBot, $command->getId(), $command->getInitiatorId(), $botIds)
		;

		$writeInviteLogResult = $this->writeAddMemberLog(
			$nonBotIds,
			$command->getId(),
			$command->getInitiatorId(),
			UserToGroupTable::ROLE_REQUEST
		);

		$handlerResult->merge($writeInviteLogResult);

		if (!empty($botIds))
		{
			$writeBotLogResult = $this->writeAddMemberLog(
				$botIds,
				$command->getId(),
				$command->getInitiatorId(),
				UserToGroupTable::ROLE_USER,
			);

			$handlerResult->merge($writeBotLogResult);
		}

		return $handlerResult;
	}
}
