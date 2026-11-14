<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\AiSummary;

use Bitrix\Main\AccessDeniedException;

/**
 * The summary is available to this user, but the element they ask it about is not theirs to read.
 *
 * A card lives long, and between the drawing of the button and the press of it the right may be gone
 * (ERR-009), so this is an ordinary answer of the scenario and not a state that should not happen.
 *
 * @internal
 */
final class ElementNotReadableException extends AccessDeniedException
{
	public function __construct(int $entityTypeId, int $entityId)
	{
		parent::__construct("User may not read element {$entityTypeId}:{$entityId}");
	}
}
