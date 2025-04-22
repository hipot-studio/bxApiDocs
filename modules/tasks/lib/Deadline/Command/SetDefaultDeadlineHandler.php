<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Command;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Deadline\Command\Trait\SendPushTrait;
use Bitrix\Tasks\Deadline\Internals\Repository\Cache\Managed\CacheDeadlineUserOptionRepository;
use Bitrix\Tasks\Deadline\Internals\Repository\DeadlineUserOptionRepositoryInterface;
use Bitrix\Tasks\Integration\Pull\PushCommand;

class SetDefaultDeadlineHandler
{
	use SendPushTrait;

	private DeadlineUserOptionRepositoryInterface $deadlineUserOptionRepository;

	public function __construct()
	{
		$serviceLocator = ServiceLocator::getInstance();

		$this->deadlineUserOptionRepository = $serviceLocator->get(CacheDeadlineUserOptionRepository::class);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function __invoke(SetDefaultDeadlineCommand $command): void
	{
		$deadlineUserOption = $this->deadlineUserOptionRepository->getByUserId($command->entity->userId);

		$deadlineUserOption->defaultDeadlineInSeconds = $command->entity->defaultDeadlineInSeconds;
		$deadlineUserOption->isExactDeadlineTime = $command->entity->isExactDeadlineTime;

		$this->deadlineUserOptionRepository->save($deadlineUserOption);

		$this->sendPush(
			$command->entity->userId,
			PushCommand::DEFAULT_DEADLINE_UPDATED,
			['deadline' => $command->entity->defaultDeadlineInSeconds],
		);
	}
}
