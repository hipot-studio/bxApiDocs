<?php

namespace Bitrix\Rest\V3\Exception;

use CRestServer;

class MethodNotFoundException extends RestException
{
	protected const STATUS = CRestServer::STATUS_NOT_FOUND;

	public function __construct(protected string $method)
	{
		parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_METHODNOTFOUNDEXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#METHOD#' => $this->method,
		];
	}
}
