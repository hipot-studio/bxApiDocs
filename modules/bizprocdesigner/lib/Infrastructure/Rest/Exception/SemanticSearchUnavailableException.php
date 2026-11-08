<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * The configured semantic search did not deliver: it replied without success, or the call to it threw.
 *
 * Raised only where the answer is told apart from an empty one - the read of an explicitly named type. The
 * read of the bound type keeps answering with an empty list, because that is what its clients have always
 * received (BRIEF § 6.4).
 *
 * Out of the exception log because the cause is already in it, with the detail this layer does not have:
 * both the search service and the field service log the failed exchange where it happens.
 */
final class SemanticSearchUnavailableException extends RestException implements SkipWriteToLogException
{
	use RussianMessageFallback;

	protected const STATUS = \CRestServer::STATUS_INTERNAL;

	protected function getMessagePhraseCode(): string
	{
		return 'BIZPROCDESIGNER_REST_EXCEPTION_SEMANTIC_SEARCH_UNAVAILABLE';
	}
}
