<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Settings;

final class UsageStatSettings extends \Bitrix\Main\Grid\Settings
{
	private bool $isBiBuilderService = false;
	private string $keyEditUrl = '';
	private ?array $ormFilter = null;

	public function setIsBiBuilderService(bool $value): self
	{
		$this->isBiBuilderService = $value;

		return $this;
	}

	public function isBiBuilderService(): bool
	{
		return $this->isBiBuilderService;
	}

	public function setKeyEditUrl(string $url): self
	{
		$this->keyEditUrl = $url;

		return $this;
	}

	public function getKeyEditUrl(): string
	{
		return $this->keyEditUrl;
	}

	public function setOrmFilter(?array $filter): self
	{
		$this->ormFilter = $filter;

		return $this;
	}

	public function getOrmFilter(): ?array
	{
		return $this->ormFilter;
	}
}
