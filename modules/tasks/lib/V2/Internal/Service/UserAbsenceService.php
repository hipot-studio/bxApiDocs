<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service;

use Bitrix\Tasks\V2\Internal\Integration\Intranet\Entity\Absence;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Entity\AbsenceCollection;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\AbsenceService;
use Bitrix\Tasks\V2\Internal\Repository\ViewedAbsenceRepositoryInterface;

class UserAbsenceService
{
	public function __construct(
		private readonly AbsenceService $absenceService,
		private readonly ViewedAbsenceRepositoryInterface $viewedAbsenceRepository,
	)
	{
	}

	/**
	 * @param int[] $userIds
	 */
	public function getVacationData(array $userIds, int $currentUserId): AbsenceCollection
	{
		$vacationData = $this->absenceService->getVacationData($userIds);
		if ($vacationData->isEmpty())
		{
			return new AbsenceCollection();
		}

		$viewed = $this->viewedAbsenceRepository->findByViewedByAndUserIds($currentUserId, $userIds);
		if ($viewed->isEmpty())
		{
			return $vacationData;
		}

		$indexedViewed = [];
		foreach ($viewed as $viewedAbsence)
		{
			$indexedViewed[$viewedAbsence->userId][$viewedAbsence->absenceId] = true;
		}

		return $vacationData->filter(
			static fn (Absence $vacation) => !isset($indexedViewed[$vacation->userId][$vacation->id])
		);
	}

	public function setViewed(int $userId, int $absenceId, int $currentUserId): void
	{
		$vacationData = $this->absenceService->getVacationData([$userId]);
		if ($vacationData->isEmpty())
		{
			return;
		}

		$absence = $vacationData->findOneById($absenceId);
		if ($absence === null || $absence->userId !== $userId)
		{
			return;
		}

		$collection = new AbsenceCollection($absence);
		$this->viewedAbsenceRepository->save($collection, $currentUserId);
	}
}
