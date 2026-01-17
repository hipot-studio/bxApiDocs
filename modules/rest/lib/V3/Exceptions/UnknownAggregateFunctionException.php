<?php

namespace Bitrix\Rest\V3\Exceptions;

class UnknownAggregateFunctionException extends RestException
{
	public function __construct(
		protected string $function,
	) {
		parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_UNKNOWNAGGREGATEFUNCTIONEXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#FUNCTION#' => $this->function,
		];
	}
}
