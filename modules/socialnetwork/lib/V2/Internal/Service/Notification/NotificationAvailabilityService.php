<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Notification;

use Bitrix\Socialnetwork\V2\Internal\Repository\ConvertProgressRepositoryInterface;

class NotificationAvailabilityService
{
	public function __construct(
		private readonly ConvertProgressRepositoryInterface $convertProgressRepository,
	)
	{
	}

	/**
	 * Returns true for native new projects and for fully converted ones.
	 *
	 * The setting is available when the convert status is "converted" in the
	 * product sense — NotRequired (native new project) or a completed conversion
	 * (CompletedFromGroup/CompletedFromCollab). It stays unavailable for legacy
	 * groups before conversion and while a conversion is in progress or stopped
	 * by error. This mirrors ConvertStatus::isConverted().
	 *
	 * Defensive: null status (record missing — legacy group before conversion)
	 * is treated as "not available".
	 */
	public function isAvailable(int $projectId): bool
	{
		if ($projectId <= 0)
		{
			return false;
		}

		$status = $this->convertProgressRepository->getStatusByGroupId($projectId);

		return $status?->isConverted() ?? false;
	}
}
