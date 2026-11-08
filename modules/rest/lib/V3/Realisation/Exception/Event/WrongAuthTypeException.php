<?php

namespace Bitrix\Rest\V3\Realisation\Exception\Event;

use Bitrix\Rest\V3\Exception\RestException;

use CRestServer;

class WrongAuthTypeException extends RestException
{
	protected const STATUS = CRestServer::STATUS_FORBIDDEN;

	public function __construct(protected string $type)
	{
		parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_REALISATION_EXCEPTION_EVENT_WRONGAUTHTYPEEXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#TYPE#' => $this->type,
		];
	}
}

