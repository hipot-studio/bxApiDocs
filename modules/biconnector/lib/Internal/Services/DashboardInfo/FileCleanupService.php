<?php

namespace Bitrix\BIConnector\Internal\Services\DashboardInfo;

use Bitrix\BIConnector\Internal\Model\SupersetDashboardInfoGalleryTable;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardInfoTable;

class FileCleanupService
{
	public function collectImageIdsByDashboardId(int $dashboardId): array
	{
		if ($dashboardId <= 0)
		{
			return [];
		}

		$dashboardInfoRows = SupersetDashboardInfoTable::getList([
			'select' => ['ID', 'IMAGE_ID'],
			'filter' => ['=DASHBOARD_ID' => $dashboardId],
		])->fetchAll();

		if (empty($dashboardInfoRows))
		{
			return [];
		}

		$dashboardInfoIds = [];
		$imageIds = [];
		foreach ($dashboardInfoRows as $dashboardInfoRow)
		{
			$dashboardInfoId = (int)($dashboardInfoRow['ID'] ?? 0);
			if ($dashboardInfoId > 0)
			{
				$dashboardInfoIds[] = $dashboardInfoId;
			}

			$coverImageId = (int)($dashboardInfoRow['IMAGE_ID'] ?? 0);
			if ($coverImageId > 0)
			{
				$imageIds[] = $coverImageId;
			}
		}

		if (!empty($dashboardInfoIds))
		{
			$galleryRows = SupersetDashboardInfoGalleryTable::getList([
				'select' => ['IMAGE_ID'],
				'filter' => ['=DASHBOARD_INFO_ID' => array_values(array_unique($dashboardInfoIds))],
			])->fetchAll();

			foreach ($galleryRows as $galleryRow)
			{
				$galleryImageId = (int)($galleryRow['IMAGE_ID'] ?? 0);
				if ($galleryImageId > 0)
				{
					$imageIds[] = $galleryImageId;
				}
			}
		}

		return $this->normalizeImageIds($imageIds);
	}

	public function getRemovedImageIds(array $currentImageIds, array $newImageIds): array
	{
		$currentImageIds = array_flip($this->normalizeImageIds($currentImageIds));
		$newImageIds = $this->normalizeImageIds($newImageIds);

		foreach ($newImageIds as $newImageId)
		{
			unset($currentImageIds[$newImageId]);
		}

		return array_values(array_map('intval', array_keys($currentImageIds)));
	}

	public function deleteFiles(array $imageIds): void
	{
		$imageIds = $this->normalizeImageIds($imageIds);
		foreach ($imageIds as $imageId)
		{
			\CFile::Delete($imageId);
		}
	}

	private function normalizeImageIds(array $imageIds): array
	{
		$normalizedImageIds = [];
		foreach ($imageIds as $imageId)
		{
			$imageId = (int)$imageId;
			if ($imageId > 0)
			{
				$normalizedImageIds[$imageId] = $imageId;
			}
		}

		return array_values($normalizedImageIds);
	}
}
