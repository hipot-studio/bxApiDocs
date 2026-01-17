<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Tracking;

use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTime;
use Bitrix\Tasks\V2\Internal\Exception\Task\ElapsedTimeException;
use Bitrix\Tasks\V2\Internal\Service\Task\ElapsedTimeService;

class AddElapsedTimeHandler
{
	public function __construct(
		private readonly ElapsedTimeService $elapsedTimeService,
	)
	{
	}

	/**
	 * @throws ElapsedTimeException
	 */
	public function __invoke(AddElapsedTimeCommand $command): ElapsedTime
	{
		[$id] = $this->elapsedTimeService->add($command->elapsedTime);

		return $command->elapsedTime->cloneWith(['id' => $id]);
	}
}
