<?php

namespace Bitrix\Rest\V3\Realisation\Exception\Event;

use Bitrix\Rest\V3\Exception\RestException;

use CRestServer;

class NotFoundException extends RestException
{
	protected const STATUS = CRestServer::STATUS_NOT_FOUND;

	public function __construct(protected string $name)
	{
		parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_REALISATION_EXCEPTION_EVENT_NOTFOUNDEXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#NAME#' => $this->name,
		];
	}
}