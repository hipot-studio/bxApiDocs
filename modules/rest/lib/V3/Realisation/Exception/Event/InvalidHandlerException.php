<?php

namespace Bitrix\Rest\V3\Realisation\Exception\Event;

use Bitrix\Rest\V3\Exception\RestException;

class InvalidHandlerException extends RestException
{
	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_REALISATION_EXCEPTION_EVENT_INVALIDHANDLEREXCEPTION';
	}
}

