<?php
declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Services\AhaMoment;

use Bitrix\Main\UI\Spotlight;

final class AhaMomentSpotlightOptions
{
	public function __construct(
		private readonly string $baseId,
		private readonly int $maxShows,
		private readonly string $userType = Spotlight::USER_TYPE_ALL,
		private readonly int $lifetime = 0,
		private readonly int|false $userId = false,
		private readonly int $showDelaySeconds = 0,
	)
	{
	}

	public function getBaseId(): string
	{
		return $this->baseId;
	}

	public function getMaxShows(): int
	{
		return $this->maxShows;
	}

	public function getUserType(): string
	{
		return $this->userType;
	}

	public function getLifetime(): int
	{
		return $this->lifetime;
	}

	public function getUserId(): int|false
	{
		return $this->userId;
	}

	public function getShowDelaySeconds(): int
	{
		return $this->showDelaySeconds;
	}
}
