<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Command;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\V2\Internal\Service\Task\DefaultDeadlineService;

class SetDefaultDeadlineHandler
{
	public function __construct(
		private readonly DefaultDeadlineService $defaultDeadlineService,
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
		$this->defaultDeadlineService->set($setDefaultDeadlineCommand->entity);
	}
}
