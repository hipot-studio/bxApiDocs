<?php

namespace Bitrix\BIConnector\Public\Command\DashboardInfoGallery;

use Bitrix\Main\Result;
use Bitrix\BIConnector\Internal\Entity\SupersetDashboardInfoGallery;
use Bitrix\Main\ORM\Objectify\Collection;

class SupersetDashboardInfoGalleryResult extends Result
{
	private ?SupersetDashboardInfoGallery $galleryItem = null;
	private ?Collection $gallery = null;

	public function getGalleryItem(): ?SupersetDashboardInfoGallery
	{
		return $this->galleryItem;
	}

	public function setGalleryItem(?SupersetDashboardInfoGallery $galleryItem): self
	{
		$this->galleryItem = $galleryItem;
		return $this;
	}

	public function getGallery(): ?Collection
	{
		return $this->gallery;
	}

	public function setGallery(?Collection $gallery): self
	{
		$this->gallery = $gallery;
		return $this;
	}
}
