<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Gates the idle watchdog of the background upload agent (JS `uploader/watchdog`): when it is off, a chunk
 * request without progress and response is never failed by the agent. Kept on by default as an emergency
 * rollback switch.
 */
final class UploadIdleWatchdogFeature extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return true;
	}
}
