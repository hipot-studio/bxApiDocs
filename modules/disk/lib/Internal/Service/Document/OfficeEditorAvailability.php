<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Document;

use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;

/**
 * Portal-wide answer whether office document editing is potentially available: document editing is on
 * and at least one office channel is enabled — OnlyOffice (including the custom server, such as R7, selected
 * as the portal viewer and ready for use) or VibeOffice.
 *
 * It does not decide which handler opens a particular file, nor check the user choice, the project scope,
 * the file format, rights or whether the remote service responds.
 */
final class OfficeEditorAvailability
{
	public static function isAvailable(): bool
	{
		return OnlyOfficeHandler::isEnabled() || VibeofficeHandler::isEnabled();
	}
}
