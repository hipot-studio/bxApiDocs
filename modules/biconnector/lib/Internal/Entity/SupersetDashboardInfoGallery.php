<?php

namespace Bitrix\BIConnector\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;

class SupersetDashboardInfoGallery implements EntityInterface
{
	private ?int $id = null;

	public function __construct(
		private readonly int $dashboardInfoId,
		private int $imageId,
		private int $sort = 500,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;
		return $this;
	}

	public function getDashboardInfoId(): int
	{
		return $this->dashboardInfoId;
	}

	public function getImageId(): int
	{
		return $this->imageId;
	}

	public function setImageId(int $imageId): self
	{
		$this->imageId = $imageId;
		return $this;
	}

	public function getSort(): int
	{
		return $this->sort;
	}

	public function setSort(int $sort): self
	{
		$this->sort = $sort;
		return $this;
	}
}
