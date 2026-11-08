<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * The caller may not read the elements of the document type it asked for.
 *
 * The message names neither the type nor anything about it. What the fields of a refused entity are - how
 * many, how they are called, which of them are custom - is exactly the knowledge this refusal exists to
 * withhold, so nothing derived from the schema reaches the answer, not even its size (BRIEF § 6.2, § 9).
 */
final class DocumentTypeAccessDeniedException extends RestException implements SkipWriteToLogException
{
	use RussianMessageFallback;

	protected const STATUS = \CRestServer::STATUS_FORBIDDEN;

	protected function getMessagePhraseCode(): string
	{
		return 'BIZPROCDESIGNER_REST_EXCEPTION_DOCUMENT_TYPE_ACCESS_DENIED';
	}
}
