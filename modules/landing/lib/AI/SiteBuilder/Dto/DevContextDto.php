<?php
namespace Bitrix\Landing\AI\SiteBuilder\Dto;

final class DevContextDto extends AbstractDto
{
	private int $siteId;
	private int $landingId;

	public function __construct(int $siteId = 0, int $landingId = 0)
	{
		$this->siteId = max(0, $siteId);
		$this->landingId = max(0, $landingId);
	}

	public function getSiteId(): int
	{
		return $this->siteId;
	}

	public function getLandingId(): int
	{
		return $this->landingId;
	}

	public function hasSite(): bool
	{
		return $this->siteId > 0 && $this->landingId > 0;
	}

	public function withSiteId(int $siteId): self
	{
		return new self($siteId, $this->landingId);
	}

	public function withLandingId(int $landingId): self
	{
		return new self($this->siteId, $landingId);
	}

	public function toArray(): array
	{
		return [
			'siteId' => $this->siteId,
			'landingId' => $this->landingId,
			'hasSite' => $this->hasSite(),
		];
	}
}
