<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use CRestServer;

/**
 * The user may read CRM forms but not change the open line chats a form can greet visitors in.
 *
 * The list exists to pick the chat a welcome form is placed in, so a chat the user may not manage is
 * no option for them and the refusal carries a code of its own.
 */
final class OpenLineAccessDeniedException extends RestException implements SkipWriteToLogException
{
	protected const STATUS = CRestServer::STATUS_FORBIDDEN;

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_FORM_OPEN_LINE_ACCESS_DENIED';
	}
}
