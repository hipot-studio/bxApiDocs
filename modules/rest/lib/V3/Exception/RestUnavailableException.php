<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Exception;

class RestUnavailableException extends AccessDeniedException
{
	protected const STATUS = \CRestServer::STATUS_PAYMENT_REQUIRED;

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_REST_UNAVAILABLE_EXCEPTION';
	}
}
