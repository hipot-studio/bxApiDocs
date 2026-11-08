<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\StaffTrack\Feature;

/**
 * Whether the office directory is switched on at all. Three conditions answer it and every entrance to
 * the directory asks the same three in the same order: the ajax surface through its prefilter, the
 * uploader of the photos, the access model of the photo storage and the public page of the offices.
 *
 * The answer is computed here rather than in the public provider above it, because the entrances of the
 * disk live in the private zone of the module and may not reach the public one.
 *
 * What the three conditions are read from is not sealed: the protected methods below are the seams a
 * test replaces - neither the settings of the portal nor the region of the license is reachable from a
 * unit test.
 */
class OfficesAvailability
{
	public function isAvailable(): bool
	{
		return $this->isCheckInEnabled()
			&& $this->isOfficesFlagEnabled()
			&& $this->isGeoFeaturesAvailableByRegion()
		;
	}

	protected function isCheckInEnabled(): bool
	{
		return Feature::isCheckInEnabledBySettings();
	}

	protected function isOfficesFlagEnabled(): bool
	{
		return Feature::isOfficesFlagEnabled();
	}

	protected function isGeoFeaturesAvailableByRegion(): bool
	{
		return Feature::isGeoFeaturesAvailableByRegion();
	}
}
