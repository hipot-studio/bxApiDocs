<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\RestException;

/**
 * The field set of the document type could not be read at all.
 *
 * About the portal and not about the request: the type was accepted and the caller was allowed to read it,
 * and then its provider answered with nothing. Reaching the exception log on purpose - an empty successful
 * list here would tell the agent the type has no fields, and it would go on to build a graph addressing none.
 */
final class DocumentFieldsUnavailableException extends RestException
{
	use RussianMessageFallback;

	protected const STATUS = \CRestServer::STATUS_INTERNAL;

	protected function getMessagePhraseCode(): string
	{
		return 'BIZPROCDESIGNER_REST_EXCEPTION_DOCUMENT_FIELDS_UNAVAILABLE';
	}
}
