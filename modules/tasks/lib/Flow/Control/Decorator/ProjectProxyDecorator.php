<?php

namespace Bitrix\Tasks\Flow\Control\Decorator;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Tasks\Flow\Control\Command\AddCommand;
use Bitrix\Tasks\Flow\Control\Command\UpdateCommand;
use Bitrix\Tasks\Flow\Control\Exception\CommandNotFoundException;
use Bitrix\Tasks\Flow\Control\Exception\FlowNotAddedException;
use Bitrix\Tasks\Flow\Control\Exception\FlowNotFoundException;
use Bitrix\Tasks\Flow\Control\Exception\FlowNotUpdatedException;
use Bitrix\Tasks\Flow\Control\Exception\InvalidCommandException;
use Bitrix\Tasks\Flow\Flow;
use Bitrix\Tasks\Flow\Integration\Socialnetwork\Exception\AutoCreationException;
use Bitrix\Tasks\Flow\Integration\Socialnetwork\GroupCommand;
use Bitrix\Tasks\Flow\Integration\Socialnetwork\GroupService;
use RuntimeException;
use Throwable;

class ProjectProxyDecorator extends AbstractFlowServiceDecorator
{
	/**
	 * @throws AutoCreationException
	 * @throws RuntimeException
	 * @throws FlowNotFoundException
	 * @throws CommandNotFoundException
	 * @throws SqlQueryException
	 * @throws InvalidCommandException
	 * @throws FlowNotAddedException
	 */
	public function add(AddCommand $command): Flow
	{
		if ($command->hasValidGroupId())
		{
			return parent::add($command);
		}

		$command->validateAdd('groupId');

		$command->groupId = $this->createProjectByFlow($command);

		return parent::add($command);
	}

	/**
	 * @throws AutoCreationException
	 * @throws RuntimeException
	 * @throws FlowNotFoundException
	 * @throws CommandNotFoundException
	 * @throws SqlQueryException
	 * @throws InvalidCommandException
	 * @throws FlowNotUpdatedException
	 */
	public function update(UpdateCommand $command): Flow
	{
		if ($command->hasValidGroupId())
		{
			return parent::update($command);
		}

		$command->validateUpdate('groupId');

		$command->groupId = $this->createProjectByFlow($command);

		return parent::update($command);
	}

	/**
	 * @throws AutoCreationException
	 * @throws InvalidCommandException
	 * @throws \Bitrix\Main\ObjectNotFoundException
	 * @throws \Psr\Container\NotFoundExceptionInterface
	 */
	protected function createProjectByFlow(AddCommand | UpdateCommand $command): int
	{
		$groupCommand = (new GroupCommand())
			->setName($command->name)
			->setOwnerId($command->creatorId)
			->setMembers($command->getUserIdList());

		/** @var GroupService $service */
		$service = ServiceLocator::getInstance()->get('tasks.flow.socialnetwork.project.service');

		return $service->add($groupCommand);
	}
}