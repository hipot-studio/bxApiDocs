<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\Validation\ValidationException;

/**
 * Refusal of the description context a catalog read was asked for: a name the block does not publish, a
 * name whose value is not the agent's to write, or a value the setting cannot hold.
 *
 * A 400 rather than the 404 an unreadable block gets: the block itself was read, and what has to be
 * corrected is the request. The errors are handed to the core as they are, so every one of them is
 * addressed at the pair it belongs to (contextSettings.2.name), in the notation the core uses for a
 * malformed request body.
 */
final class BlockContextRejectedException extends ValidationException
{
	use RussianMessageFallback;

	protected function getMessagePhraseCode(): string
	{
		return 'BIZPROCDESIGNER_REST_EXCEPTION_BLOCK_CONTEXT_REJECTED';
	}
}
