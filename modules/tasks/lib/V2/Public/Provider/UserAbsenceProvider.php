<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Tasks\V2\Internal\Integration\Intranet\Entity\AbsenceCollection;
use Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service\OperationAccessService;
use Bitrix\Tasks\V2\Internal\Service\UserAbsenceService;

class UserAbsenceProvider
{
	public function __construct(
		private readonly UserAbsenceService $userAbsenceService,
		private readonly OperationAccessService $operationAccessService,
	)
	{
	}

	public function get(array $userIds, int $currentUserId): AbsenceCollection
	{
		$accessibleUserIds = $this->operationAccessService->filterUsersWhoCanViewProfile(
			$currentUserId,
			$userIds,
		);

		if (empty($accessibleUserIds))
		{
			return new AbsenceCollection();
		}

		return $this->userAbsenceService->getVacationData($accessibleUserIds, $currentUserId);
	}
}
