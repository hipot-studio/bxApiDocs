<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\Validation\ValidationException;

/**
 * The server accepted the description context and then could not build the description for it.
 *
 * Its own class, and therefore its own top level code, because that is the whole point of the refusal: the
 * caller cannot correct a name or a value here, and an answer it could not tell from a rejected value would
 * send it round the same request again. Never an empty schema in place of this: a map whose keys the server
 * failed to resolve would then read as a map that admits any key (BRIEF § 1.4).
 */
final class BlockContextResolutionFailedException extends ValidationException
{
	use RussianMessageFallback;

	protected function getMessagePhraseCode(): string
	{
		return 'BIZPROCDESIGNER_REST_EXCEPTION_BLOCK_CONTEXT_RESOLUTION_FAILED';
	}
}
