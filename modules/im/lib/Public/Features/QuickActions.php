<?php

declare(strict_types=1);

namespace Bitrix\Im\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Opt-in for the quick actions of the messenger: the ways to start work on a task or a meeting
 * without leaving the chat list. Default off, and it covers the quick actions of the project as a
 * whole, so their entry points are rolled out together. Ask it from the im module only: a flag class
 * the kernel cannot resolve counts as already rolled out and answers true.
 */
final class QuickActions extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
