<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\ViewedAbsenceCollection;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Entity\AbsenceCollection;

interface ViewedAbsenceRepositoryInterface
{
	public function findByViewedByAndUserIds(int $currentUserId, array $userIds): ViewedAbsenceCollection;

	public function save(AbsenceCollection $vacationData, int $userId): void;

	public function deleteTill(DateTime $dateTime): void;
}
