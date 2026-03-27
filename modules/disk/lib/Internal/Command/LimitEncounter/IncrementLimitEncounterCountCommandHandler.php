<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command\LimitEncounter;

use Bitrix\Disk\Internal\Repository\Interface\LimitEncounterCounterRepositoryInterface;
use Bitrix\Disk\Internal\Service\ItemsCountResult;

class IncrementLimitEncounterCountCommandHandler
{
	public function __construct(
		private readonly LimitEncounterCounterRepositoryInterface $repository,
	)
	{
	}

	public function __invoke(IncrementLimitEncounterCountCommand $command): ItemsCountResult
	{
		return $this->repository->incrementUnlessMax($command->type, $command->max);
	}
}
