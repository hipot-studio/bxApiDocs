<?php

declare(strict_types=1);

namespace Bitrix\Disk\Config\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * The portal switch of the VibeOffice editor. Evaluate it in the neutral context (no user, no params): the
 * editor of a document is one for its employees, guests and background jobs alike.
 *
 * Off by default. Read it through {@see \Bitrix\Disk\Document\Vibeoffice\Feature::isEnabledOnPortal()}: for now
 * the legacy option disk_vibeoffice_enabled = Y turns VibeOffice on as well, so portals keep their state without
 * a migration.
 */
final class VibeofficeFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
