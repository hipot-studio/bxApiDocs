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

		[$userCodes, $departmentCodes] = $this->splitAccessCodes($members);

		if (!empty($departmentCodes))
		{
			Container::getInstance()
				->getStructureRelationService()
				->linkDepartments($departmentCodes, $entity->getId());
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

		ActionMessageBuffer::getInstance()
			->put(ActionType::AddUser, $entity->getId(), $command->getInitiatorId(), $employeeIds)
			->put(ActionType::AddGuest, $entity->getId(), $command->getInitiatorId(), $guestIds)
			->put(ActionType::AddBot, $entity->getId(), $command->getInitiatorId(), $botIds)
		;

		return $handlerResult;
	}
}
