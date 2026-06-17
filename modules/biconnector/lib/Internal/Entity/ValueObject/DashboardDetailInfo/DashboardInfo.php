<?php

namespace Bitrix\BIConnector\Internal\Entity\ValueObject\DashboardDetailInfo;

use Bitrix\Main\Type\DateTime;

class DashboardInfo
{
	public function __construct(
		public readonly string $title,
		public readonly string $type,
		public readonly int $viewsCount,
		private readonly ?string $partnerName,
		private readonly ?string $icon,
		private readonly array $images,
		private readonly ?string $description,
		private readonly ?int $publishedById,
		private readonly ?DateTime $publishedDate,
		private readonly ?int $updatedById,
		private readonly ?DateTime $updatedDate,
		private readonly ?string $filterPeriod = null,
		private readonly ?string $dateFilterStart = null,
		private readonly ?string $dateFilterEnd = null,
		private readonly bool $includeLastFilterDate = false,
		private readonly ?string $appCode = null,
		private readonly array $ratingInfo = [],
	)
	{
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function getViewsCount(): int
	{
		return $this->viewsCount;
	}

	public function getPartnerName(): ?string
	{
		return $this->partnerName;
	}

	public function getIcon(): ?string
	{
		return $this->icon;
	}

	public function getImages(): array
	{
		return $this->images;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function getPublishedById(): ?int
	{
		return $this->publishedById;
	}

	public function getPublishedDate(): ?DateTime
	{
		return $this->publishedDate;
	}

	public function getUpdatedById(): ?int
	{
		return $this->updatedById;
	}

	public function getUpdatedDate(): ?DateTime
	{
		return $this->updatedDate;
	}

	public function getFilterPeriod(): ?string
	{
		return $this->filterPeriod;
	}

	public function getDateFilterStart(): ?string
	{
		return $this->dateFilterStart;
	}

	public function getDateFilterEnd(): ?string
	{
		return $this->dateFilterEnd;
	}

	public function isIncludeLastFilterDate(): bool
	{
		return $this->includeLastFilterDate;
	}

	public function getAppCode(): ?string
	{
		return $this->appCode;
	}

	public function getRatingInfo(): array
	{
		return $this->ratingInfo;
	}
}
