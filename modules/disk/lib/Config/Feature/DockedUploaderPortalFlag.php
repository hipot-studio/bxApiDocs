<?php

namespace Bitrix\Disk\Config\Feature;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Feature\Context;

/**
 * Mounts the docked uploader on every portal page, not only on the Disk pages.
 *
 * A second step on top of {@see DockedUploaderFlag}: it is never on while the uploader itself is off,
 * and the requirement asks the first flag for the same context, so a gradual rollout by rules settles
 * both steps for one and the same user. Only the portal entry point reads it; the controllers stay
 * behind the first flag.
 */
final class DockedUploaderPortalFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}

	public function getRequirements(): array
	{
		return [
			static fn (Context $context): bool => Feature::isEnabled(DockedUploaderFlag::class, $context),
		];
	}
}
