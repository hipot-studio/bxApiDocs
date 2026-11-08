<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

class CannotReduceIncomingWebhookExpirationException extends RestException implements SkipWriteToLogException
{
	protected function getMessagePhraseCode(): string
	{
		return 'REST_REST_INCOMING_WEBHOOK_CANNOT_REDUCE_EXPIRATION_EXCEPTION';
	}
}
