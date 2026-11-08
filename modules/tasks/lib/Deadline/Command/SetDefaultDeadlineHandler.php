<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Command;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\V2\Internal\Entity\Task\State;
use Bitrix\Tasks\V2\Internal\Service\Task\StateService;

class SetDefaultDeadlineHandler
{
	public function __construct(
		private readonly StateService $stateService,
	)
	{
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function __invoke(SetDefaultDeadlineCommand $setDefaultDeadlineCommand): void
	{
		$this->stateService->set(
			State::mapFromArray(['defaultDeadline' => $setDefaultDeadlineCommand->entity]),
			$setDefaultDeadlineCommand->entity->userId,
		);
	}
}
