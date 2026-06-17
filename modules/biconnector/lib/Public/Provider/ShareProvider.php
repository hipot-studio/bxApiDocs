<?php

namespace Bitrix\BIConnector\Public\Provider;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardShare;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareRepository;
use Bitrix\BIConnector\Superset\Dashboard\ShareExpireAgent;
use Bitrix\BIConnector\Superset\Dashboard\SharePullService;

class ShareProvider
{
	public function __construct(private readonly SupersetDashboardShareRepository $repository)
	{
	}

	public function getByToken(string $token): ?SupersetDashboardShare
	{
		$share = $this->repository->getByToken($token);

		return $this->cleanupIfExpired($share);
	}

	public function getByDashboardAndUser(int $dashboardId, int $userId): ?SupersetDashboardShare
	{
		$share = $this->repository->getByDashboardAndUser($dashboardId, $userId);

		return $this->cleanupIfExpired($share);
	}

	private function cleanupIfExpired(?SupersetDashboardShare $share): ?SupersetDashboardShare
	{
		if ($share === null || !$share->isExpired())
		{
			return $share;
		}

		SharePullService::sendRevokeEvent($share->getToken());
		ShareExpireAgent::remove($share->getId());
		$this->repository->delete($share->getId());

		return null;
	}

	/**
	 * @return SupersetDashboardShare[]
	 */
	public function getByDashboardId(int $dashboardId): array
	{
		return $this->repository->getByDashboardId($dashboardId);
	}
}
