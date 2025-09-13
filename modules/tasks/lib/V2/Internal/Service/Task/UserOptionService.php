<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Tasks\V2\Internal\Service\Task\Option\Action\Add;
use Bitrix\Tasks\V2\Internal\Service\Task\Option\Action\Add\RunAddEvent;
use Bitrix\Tasks\V2\Internal\Service\Task\Option\Action\Delete;
use Bitrix\Tasks\V2\Internal\Service\Task\Option\Action\Delete\RunDeleteEvent;
use Bitrix\Tasks\V2\Internal\Repository\UserOptionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Entity;

class UserOptionService
{
	public function __construct(
		private readonly UserOptionRepositoryInterface $userOptionRepository,
	)
	{

	}

	public function add(Entity\Task\UserOption $userOption): void
	{
		$this->userOptionRepository->add($userOption);

		(new Add\SendPush())($userOption);

		(new RunAddEvent())($userOption);
	}

	public function delete(Entity\Task\UserOption $userOption): void
	{
		$this->userOptionRepository->delete([$userOption->code], $userOption->taskId, $userOption->userId);

		(new Delete\SendPush())($userOption);

		(new RunDeleteEvent())($userOption);
	}
}