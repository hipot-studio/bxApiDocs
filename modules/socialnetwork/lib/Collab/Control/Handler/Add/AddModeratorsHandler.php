<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control\Handler\Add;

use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageBuffer;
use Bitrix\Socialnetwork\Control\Member\Trait\AddMemberTrait;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageFactory;
use Bitrix\Socialnetwork\Collab\Integration\IM\Messenger;
use Bitrix\Socialnetwork\Control\Command\AddCommand;
use Bitrix\Socialnetwork\Control\Handler\Add\AddHandlerInterface;
use Bitrix\Socialnetwork\Control\Handler\HandlerResult;
use Bitrix\Socialnetwork\Control\Member\Trait\GetMembersTrait;
use Bitrix\Socialnetwork\Integration\HumanResources\AccessCodeConverter;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\UserToGroupTable;

class AddModeratorsHandler implements AddHandlerInterface
{
	use GetMembersTrait;
	use AddMemberTrait;

	public function add(AddCommand $command, Workgroup $entity): HandlerResult
	{
		$handlerResult = new HandlerResult();

		$moderators = $command->getModeratorMembers();
		if (empty($moderators))
		{
			return $handlerResult;
		}

		$membersByCommand = (new AccessCodeConverter(...$moderators))
			->getUsers()
			->getUserIds();

		$add = array_diff($membersByCommand, $this->getMemberIds($entity->getId()));

		$handlerResult = $this->addMembers(
			$entity->getId(),
			$command->getInitiatorId(),
			UserToGroupTable::ROLE_MODERATOR,
			UserToGroupTable::INITIATED_BY_GROUP,
			...$add,
		);

		if (!$handlerResult->isSuccess())
		{
			return $handlerResult;
		}

		$buffer = ActionMessageBuffer::getInstance();

		// A moderator of a project being created is in the creation card among the members, so the card is all
		// that is said about them; the chat membership goes through as always.
		$parameters = $buffer->areMemberMessagesSuppressed($entity->getId())
			? [ActionMessageBuffer::WITHOUT_MESSAGE_PARAMETER => true]
			: []
		;

		$buffer
			->put(ActionType::AddUser, $entity->getId(), $command->getInitiatorId(), $add, $parameters)
			->flush();

		Messenger::setManagers($entity->getId(), $add);

		return $handlerResult;
	}
}