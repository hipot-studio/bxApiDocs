<?php

namespace Bitrix\Rest\V3\Realisation\Exception\Event;

use Bitrix\Rest\V3\Exception\RestException;
use CRestServer;

class AlreadyBoundException extends RestException
{
	protected const STATUS = CRestServer::STATUS_CONFLICT;

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_REALISATION_EXCEPTION_EVENT_ALREADYBOUNDEXCEPTION';
	}
}

