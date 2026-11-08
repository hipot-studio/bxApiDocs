<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

/**
 * A link handler that may open the file it was built for in editing without the address asking for it.
 * Not implementing this interface is the boundary of the automatic choice: the renderer keeps no list
 * of exceptions of its own.
 */
interface AutomaticEditModeCapable
{
	public function supportsAutomaticEditMode(): bool;
}
