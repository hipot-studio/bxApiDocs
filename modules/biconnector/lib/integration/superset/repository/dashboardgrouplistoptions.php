<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Integration\Superset\Repository;

final readonly class DashboardGroupListOptions
{
	private function __construct(
		public DashboardGroupListMode $mode,
		public array $dashboardIds,
		public ?int $targetDashboardId = null,
	)
	{
	}

	public static function createDefault(): self
	{
		return new self(DashboardGroupListMode::Default, []);
	}

	public static function createRoot(array $dashboardIds): self
	{
		return new self(DashboardGroupListMode::RootWithPromotedDashboards, self::normalizeIds($dashboardIds));
	}

	public static function createFolder(array $dashboardIds): self
	{
		return new self(DashboardGroupListMode::FolderWithExcludedDashboards, self::normalizeIds($dashboardIds));
	}

	public function withTargetDashboardId(int $targetDashboardId): self
	{
		return new self($this->mode, $this->dashboardIds, $targetDashboardId);
	}

	private static function normalizeIds(array $dashboardIds): array
	{
		$result = [];

		foreach ($dashboardIds as $dashboardId)
		{
			$dashboardId = (int) $dashboardId;

			if ($dashboardId > 0)
			{
				$result[$dashboardId] = $dashboardId;
			}
		}

		return array_values($result);
	}
}
