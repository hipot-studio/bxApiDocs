<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\User;

use Bitrix\Tasks\V2\Internal\Service\UserAbsenceService;

class ViewUserAbsenceHandler
{
	public function __construct(
		private readonly UserAbsenceService $userAbsenceService,
	)
	{
	}

	public function __invoke(ViewUserAbsenceCommand $command): void
	{
		$this->userAbsenceService->setViewed(
			$command->userId,
			$command->absenceId,
			$command->currentUserId
		);
	}
}
