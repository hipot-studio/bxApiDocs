<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

/**
 * A handler whose page may offer moving the opening to editing. The address of that move is answered by the
 * render alone, so the handler is told it and never chooses it.
 */
interface EditTransitionAware
{
	public function setEditTransitionUrl(?string $url): void;
}
