<?php

namespace Bitrix\Tasks\Flow\Control\Decorator;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Flow\Control\Command\AddCommand;
use Bitrix\Tasks\Flow\Control\Command\UpdateCommand;
use Bitrix\Tasks\Flow\Control\Exception\CommandNotFoundException;
use Bitrix\Tasks\Flow\Control\Exception\FlowNotAddedException;
use Bitrix\Tasks\Flow\Control\Exception\FlowNotFoundException;
use Bitrix\Tasks\Flow\Control\Exception\FlowNotUpdatedException;
use Bitrix\Tasks\Flow\Control\Exception\InvalidCommandException;
use Bitrix\Tasks\Flow\Flow;
use Bitrix\Tasks\Flow\Integration\Socialnetwork\MemberService;
use Psr\Container\NotFoundExceptionInterface;

class ProjectMembersProxyDecorator extends AbstractFlowServiceDecorator
{
	/**
	 * @throws CommandNotFoundException
	 * @throws InvalidCommandException
	 * @throws FlowNotFoundException
	 * @throws FlowNotAddedException
	 * @throws NotFoundExceptionInterface
	 * @throws SqlQueryException
	 */
	public function add(AddCommand $command): Flow
	{
		if ($command->hasValidGroupId() && $command->hasValidOwnerId())
		{
			$this->addOwnerToProject($command);
		}

		return parent::add($command);
	}

	/**
	 * @throws CommandNotFoundException
	 * @throws InvalidCommandException
	 * @throws FlowNotFoundException
	 * @throws NotFoundExceptionInterface
	 * @throws FlowNotUpdatedException
	 * @throws SqlQueryException
	 */
	public function update(UpdateCommand $command): Flow
	{
		if ($command->isOwnerIdFilled() && $command->hasValidGroupId() && $command->hasValidOwnerId())
		{
			$this->addOwnerToProject($command);
		}

		return parent::update($command);
	}

	/**
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function addOwnerToProject(AddCommand|UpdateCommand $command): void
	{
		(new MemberService())->addMembers($command->groupId, [$command->ownerId], $command->ownerId);
	}
}
