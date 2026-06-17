<?php

namespace Bitrix\BIConnector\Integration\Market;

use Bitrix\Main\Loader;
use Bitrix\Market\Application\MarketDetail;
use Bitrix\Market\Detail\DetailType;

class AppDetail
{
	private ?array $info = null;

	public function __construct(?string $appCode = null, ?array $info = null)
	{
		if ($info !== null)
		{
			$this->info = $info;

			return;
		}

		if ($appCode === null || !Loader::includeModule('market'))
		{
			return;
		}

		$marketDetail = new MarketDetail($appCode, DetailType::App);
		try
		{
			$this->info = $marketDetail->getInfo();
		}
		catch (\Exception)
		{
			$this->info = null;
		}
	}

	public static function createFromInfo(array $info): self
	{
		return new self(info: $info);
	}

	public function getInfo(): ?array
	{
		return $this->info;
	}

	public function getPartnerName(): ?string
	{
		return $this->info['PARTNER_NAME'] ?? null;
	}

	public function getDescription(): ?string
	{
		return $this->info['DESC'] ?? null;
	}

	public function getIcon(): ?string
	{
		return $this->info['ICON'] ?? null;
	}

	public function getImages(): array
	{
		return $this->info['IMAGES'] ?? [];
	}

	public function getReviews(): array
	{
		return is_array($this->info['REVIEWS'] ?? null) ? $this->info['REVIEWS'] : [];
	}
}
