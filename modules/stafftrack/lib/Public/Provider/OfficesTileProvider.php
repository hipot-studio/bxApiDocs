<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider;

/**
 * The tile of the office directory as the portal outside the module asks for it: one answer that
 * carries both the rule of whether the tile is shown at all and everything it is drawn from, so no
 * consumer has to know the gate of the directory or what an empty portal means.
 */
class OfficesTileProvider
{
	public function getTile(): ?array
	{
		if (!$this->isFeatureAvailable())
		{
			return null;
		}

		$count = $this->getCount();

		if ($count <= 0)
		{
			return null;
		}

		return [
			'count' => $count,
			'url' => $this->getSiteDir() . OfficesMenuItemProvider::PAGE_PATH,
		];
	}

	protected function isFeatureAvailable(): bool
	{
		return (new OfficesAvailabilityProvider())->isAvailable();
	}

	protected function getCount(): int
	{
		return (new OfficesCountProvider())->getCount();
	}

	protected function getSiteDir(): string
	{
		return defined('SITE_DIR') ? SITE_DIR : '/';
	}
}
