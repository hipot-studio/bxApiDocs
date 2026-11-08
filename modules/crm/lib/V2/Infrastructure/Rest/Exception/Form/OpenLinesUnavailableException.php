<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use CRestServer;

/**
 * The portal has no open lines module, so it has no chats to answer with at all.
 *
 * An absent module is not a refusal of access: the caller learns that the functionality is missing
 * on this portal rather than that its own permissions are short.
 */
final class OpenLinesUnavailableException extends RestException implements SkipWriteToLogException
{
	protected const STATUS = CRestServer::STATUS_FORBIDDEN;

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_FORM_OPEN_LINES_UNAVAILABLE';
	}
}
