<?php

namespace Bitrix\BIConnector\Internal\Entity\ValueObject\DashboardCreate;

final class DashboardSaveData
{
	public function __construct(
		public readonly string $title,
		public readonly ?string $description,
		public readonly array $groups,
		public readonly array $scopes,
		public readonly array $params,
		public readonly array $coverImage = [],
		public readonly array $galleryImage = [],
		public readonly array $period = [],
	)
	{
	}

	public function getCoverImageId(): ?int
	{
		$imageId = $this->coverImage['id'] ?? null;

		return is_int($imageId) && $imageId > 0 ? $imageId : null;
	}

	public function getCoverImageTempFileId(): ?string
	{
		$tempFileId = $this->coverImage['tempFileId'] ?? null;

		return is_string($tempFileId) && trim($tempFileId) !== '' ? $tempFileId : null;
	}

	public function getGalleryImageIds(): array
	{
		$imageIds = $this->galleryImage['ids'] ?? [];

		return is_array($imageIds) ? $imageIds : [];
	}

	public function getGalleryImageTempFileIds(): array
	{
		$tempFileIds = $this->galleryImage['tempFileIds'] ?? [];

		return is_array($tempFileIds) ? $tempFileIds : [];
	}

	public function getPeriodData(): array
	{
		return $this->period;
	}
}
