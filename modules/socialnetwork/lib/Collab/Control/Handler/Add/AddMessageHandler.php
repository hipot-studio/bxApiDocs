<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control\Handler\Add;

use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageBuffer;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageFactory;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\Control\Command\AddCommand;
use Bitrix\Socialnetwork\Control\Handler\Add\AddHandlerInterface;
use Bitrix\Socialnetwork\Control\Handler\HandlerResult;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\V2\Feature;

class AddMessageHandler implements AddHandlerInterface
{
	/**
	 * @throws LoaderException
	 * @throws ObjectNotFoundException
	 */
	public function add(AddCommand $command, Workgroup $entity): HandlerResult
	{
		$factory = ActionMessageFactory::getInstance();

		if (Feature::isNewProjectsOn())
		{
			$factory->getActionMessage(
				ActionType::CreateProjectRich,
				$entity->getId(),
				$command->getInitiatorId(),
			)->send();

			$factory->getActionMessage(
				ActionType::CreateProject,
				$entity->getId(),
				$command->getInitiatorId(),
			)->send(parameters: [
				'goal' => $command->getGoal(),
				'dateStart' => $command->getDateStart(),
				'dateFinish' => $command->getDateFinish(),
				'ownerId' => $command->getOwnerId(),
				'members' => $this->collectMembers($command),
			]);

			// The card has just named the composition the project is created with, so the handlers that build
			// that composition add their members to the chat without announcing them once more.
			ActionMessageBuffer::getInstance()->suppressMemberMessages($entity->getId());
		}
		else
		{
			$factory->getActionMessage(
				ActionType::CreateCollab,
				$entity->getId(),
				$command->getInitiatorId()
			)->send();
		}

		return new HandlerResult();
	}

	/**
	 * Moderators travel with the members: the card shows who is in the project, not how the rights are split.
	 *
	 * @return string[] access codes of people, departments and teams
	 */
	private function collectMembers(AddCommand $command): array
	{
		return array_merge(
			$command->getMembers() ?? [],
			$command->getModeratorMembers() ?? [],
		);
	}
}
