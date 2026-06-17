<?php
declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Services\AhaMoment;

final class AhaMomentSpotlightConfig
{
	public function __construct(
		private readonly bool $canShow,
		private readonly ?string $spotlightId = null,
		private readonly ?int $showIndex = null,
		private readonly int $showDelaySeconds = 0,
	)
	{
	}

	public function canShow(): bool
	{
		return $this->canShow;
	}

	public function getSpotlightId(): ?string
	{
		return $this->spotlightId;
	}

	public function getShowIndex(): ?int
	{
		return $this->showIndex;
	}

	public function getShowDelaySeconds(): int
	{
		return $this->showDelaySeconds;
	}
}
