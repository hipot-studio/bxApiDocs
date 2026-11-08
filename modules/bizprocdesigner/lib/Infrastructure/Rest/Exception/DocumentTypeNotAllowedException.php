<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * The requested document type is not one the bound template may name.
 *
 * Told apart from the refusal by rights on purpose: this one says the type is not reachable from this
 * template at all, and no permission would change that, so the caller has to ask for another type rather
 * than for more rights. Raised before any right is consulted, so it discloses nothing about the caller's
 * access ({@see DocumentTypeAccessDeniedException} is the other answer).
 *
 * The type is not echoed and neither is the set: the answer is about this request, and the caller reads the
 * types it may name off its own block catalog.
 */
final class DocumentTypeNotAllowedException extends RestException implements SkipWriteToLogException
{
	use RussianMessageFallback;

	protected const STATUS = \CRestServer::STATUS_WRONG_REQUEST;

	protected function getMessagePhraseCode(): string
	{
		return 'BIZPROCDESIGNER_REST_EXCEPTION_DOCUMENT_TYPE_NOT_ALLOWED';
	}
}
