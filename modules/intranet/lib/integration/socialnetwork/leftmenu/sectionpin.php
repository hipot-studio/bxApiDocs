<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Integration\Socialnetwork\LeftMenu;

use Bitrix\Intranet\UI\LeftMenu\Favorites\ProjectSectionDecorator;

/**
 * Capability marker for modules that render the section pin control.
 *
 * Consumers must tolerate the absence of this class: an older intranet does not ship it
 * and cannot render a pinned project section, so the control has to stay hidden there.
 */
class SectionPin
{
	public static function isSupported(): bool
	{
		return class_exists(ProjectSectionDecorator::class);
	}
}
