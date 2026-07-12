<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\User;

use Bitrix\Tasks\V2\Internal\Repository\ViewedAbsenceRepositoryInterface;

class ClearOldViewedAbsenceHandler
{
	public function __construct(
		private readonly ViewedAbsenceRepositoryInterface $viewedAbsenceRepository,
	)
	{
	}

	public function __invoke(ClearOldViewedAbsenceCommand $command): void
	{
		$this->viewedAbsenceRepository->deleteTill($command->dateTime);
	}
}
