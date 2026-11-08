<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Main\Error;
use Bitrix\Rest\V3\Exception\Validation\ValidationException;

/**
 * The requested document type is not written in the canonical form the action reads.
 *
 * A malformed request field, so a 400 addressed at the field itself. The value is not echoed: what the
 * caller needs is the rule it broke, and the rule is short enough to state in full.
 */
final class DocumentTypeInvalidException extends ValidationException
{
	use RussianMessageFallback;

	public function __construct()
	{
		parent::__construct([
			new Error('documentType must be written as module@entity@documentType', 'documentType'),
		]);
	}

	protected function getMessagePhraseCode(): string
	{
		return 'BIZPROCDESIGNER_REST_EXCEPTION_DOCUMENT_TYPE_INVALID';
	}
}
