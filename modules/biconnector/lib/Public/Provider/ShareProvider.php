<?php

namespace Bitrix\BIConnector\Public\Provider;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardShare;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareBanRepository;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareRepository;
use Bitrix\BIConnector\Internal\Services\Share\AuthorShareStateService;
use Bitrix\BIConnector\Public\Features\PublicLinksControl;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\DI\ServiceLocator;

class ShareProvider
{
	private readonly SupersetDashboardShareBanRepository $banRepository;
	private readonly AuthorShareStateService $stateService;

	public function __construct(
		private readonly SupersetDashboardShareRepository $repository,
		?SupersetDashboardShareBanRepository $banRepository = null,
	)
	{
		$this->banRepository = $banRepository ?? ServiceLocator::getInstance()->get('biconnector.repository.shareBan');
		$this->stateService = new AuthorShareStateService($this->repository, $this->banRepository);
	}

	public function getByToken(string $token): ?SupersetDashboardShare
	{
		return $this->repository->getByToken($token);
	}

	public function getByDashboardAndUser(int $dashboardId, int $userId): ?SupersetDashboardShare
	{
		return $this->repository->getByDashboardAndUser($dashboardId, $userId);
	}

	/**
	 * @return SupersetDashboardShare[]
	 */
	public function getByDashboardId(int $dashboardId): array
	{
		return $this->repository->getByDashboardId($dashboardId);
	}

	/**
	 * State of the author's own link for the share popup. The ban and the switch-off by an administrator
	 * are reported only while the public links control is on.
	 *
	 * @return array{
	 *     isActive: bool,
	 *     password: string,
	 *     dateExpireTimestamp: int,
	 *     isBanned?: bool,
	 *     disabledByAdmin?: ?array{id: int, name: string, dateTimestamp: ?int},
	 * }|null Null when the author has no link to the dashboard.
	 */
	public function getAuthorShareState(int $dashboardId, int $userId): ?array
	{
		return $this->getAuthorShareStates([$dashboardId], $userId)[$dashboardId] ?? null;
	}

	/**
	 * States of the author's own links to several dashboards, read with a fixed number of queries.
	 *
	 * @param int[] $dashboardIds
	 * @return array<int, array> Dashboard id => state as {@see getAuthorShareState()} gives it; dashboards
	 *     without the author's link are absent.
	 */
	public function getAuthorShareStates(array $dashboardIds, int $userId): array
	{
		return $this->stateService->getStates($dashboardIds, $userId, $this->isControlEnabled());
	}

	/**
	 * Whether the author interface shows the dashboard as closed for public links.
	 */
	public function isShareBanned(int $dashboardId): bool
	{
		return $this->isControlEnabled() && $this->banRepository->isBanned($dashboardId);
	}

	private function isControlEnabled(): bool
	{
		return Feature::isEnabled(PublicLinksControl::class);
	}
}
