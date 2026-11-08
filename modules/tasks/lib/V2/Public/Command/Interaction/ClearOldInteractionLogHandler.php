<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Interaction;

use Bitrix\Tasks\V2\Internal\Repository\InteractionLogRepositoryInterface;

class ClearOldInteractionLogHandler
{
	public function __construct(
		private readonly InteractionLogRepositoryInterface $interactionLogRepository,
	)
	{
	}

	public function __invoke(ClearOldInteractionLogCommand $command): void
	{
		$this->interactionLogRepository->purgeOlderThan($command->dateTime);
	}
}
