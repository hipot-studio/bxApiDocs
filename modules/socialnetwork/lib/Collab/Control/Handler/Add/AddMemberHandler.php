<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control\Handler\Add;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Socialnetwork\Collab\Control\Handler\Trait\SplitAccessCodesTrait;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageBuffer;
use Bitrix\Socialnetwork\Provider\EmployeeProvider;
use Bitrix\Socialnetwork\Control\Member\Trait\AddMemberTrait;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\Control\Command\AddCommand;
use Bitrix\Socialnetwork\Control\Handler\Add\AddHandlerInterface;
use Bitrix\Socialnetwork\Control\Handler\HandlerResult;
use Bitrix\Socialnetwork\Control\Member\Trait\GetMembersTrait;
use Bitrix\Socialnetwork\Integration\HumanResources\AccessCodeConverter;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class AddMemberHandler implements AddHandlerInterface
{
	use GetMembersTrait;
	use AddMemberTrait;
	use SplitAccessCodesTrait;

	/**
	 * @throws LoaderException
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function add(AddCommand $command, Workgroup $entity): HandlerResult
	{
		$handlerResult = new HandlerResult();

		$members = $command->getMembers();
		if (empty($members))
		{
			return $handlerResult;
		}

		[$userCodes, $structureCodes] = $this->splitAccessCodes($members);

		if (!empty($structureCodes))
		{
			$linkResult = Container::getInstance()
				->getStructureRelationService()
				->linkNodes($structureCodes, $entity->getId(), $command->getInitiatorId());

			if (!$linkResult->isSuccess())
			{
				return $handlerResult->merge($linkResult);
			}
		}

		if (empty($userCodes))
		{
			return $handlerResult;
		}

		$membersByCommand = (new AccessCodeConverter(...$userCodes))
			->getAccessCodeIdList();

		$add = array_diff($membersByCommand, $this->getMemberIds($entity->getId()));

		$handlerResult = $this->addMembers(
			$entity->getId(),
			$command->getInitiatorId(),
			UserToGroupTable::ROLE_USER,
			UserToGroupTable::INITIATED_BY_GROUP,
			...$add,
		);

		if (!$handlerResult->isSuccess())
		{
			return $handlerResult;
		}

		[$employeeIds, $guestIds, $botIds] =
			EmployeeProvider::getInstance()
				->splitIntoEmployeesGuestsAndBots($add)
		;

		$buffer = ActionMessageBuffer::getInstance();
		$parameters = $this->getMessageParameters($buffer, $entity->getId());

		$buffer
			->put(ActionType::AddUser, $entity->getId(), $command->getInitiatorId(), $employeeIds, $parameters)
			->put(ActionType::AddGuest, $entity->getId(), $command->getInitiatorId(), $guestIds, $parameters)
			->put(ActionType::AddBot, $entity->getId(), $command->getInitiatorId(), $botIds, $parameters)
		;

		return $handlerResult;
	}

	/**
	 * People added while the project is being created reach the chat as always, but say nothing of themselves:
	 * the creation card already names the composition the project starts with.
	 */
	private function getMessageParameters(ActionMessageBuffer $buffer, int $collabId): array
	{
		return $buffer->areMemberMessagesSuppressed($collabId)
			? [ActionMessageBuffer::WITHOUT_MESSAGE_PARAMETER => true]
			: []
		;
	}
}
