<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Tracking;

use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTime;
use Bitrix\Tasks\V2\Internal\Exception\Task\ElapsedTimeException;
use Bitrix\Tasks\V2\Internal\Exception\Task\ElapsedTimeNotFoundException;
use Bitrix\Tasks\V2\Internal\Service\Task\ElapsedTimeService;

class UpdateElapsedTimeHandler
{
	public function __construct(
		private readonly ElapsedTimeService $elapsedTimeService,
	)
	{
	}

	/**
	 * @throws ElapsedTimeException
	 * @throws ElapsedTimeNotFoundException
	 */
	public function __invoke(UpdateElapsedTimeCommand $command): ElapsedTime
	{
		return $this->elapsedTimeService->update($command->elapsedTime);
	}
}

