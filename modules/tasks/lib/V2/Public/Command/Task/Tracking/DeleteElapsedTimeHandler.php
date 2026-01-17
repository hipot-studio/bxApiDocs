<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Tracking;

use Bitrix\Tasks\V2\Internal\Exception\Task\ElapsedTimeException;
use Bitrix\Tasks\V2\Internal\Exception\Task\ElapsedTimeNotFoundException;
use Bitrix\Tasks\V2\Internal\Service\Task\ElapsedTimeService;
use Bitrix\Tasks\V2\Internal\Service\Trait\ApplicationErrorTrait;

class DeleteElapsedTimeHandler
{
	use ApplicationErrorTrait;

	public function __construct(
		private readonly ElapsedTimeService $elapsedTimeService,
	)
	{
	}

	/**
	 * @throws ElapsedTimeException
	 * @throws ElapsedTimeNotFoundException
	 */
	public function __invoke(DeleteElapsedTimeCommand $command): bool
	{
		return $this->elapsedTimeService->delete($command->elapsedTime);
	}
}

