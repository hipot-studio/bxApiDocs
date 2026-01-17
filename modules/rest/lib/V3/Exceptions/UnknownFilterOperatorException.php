<?php

namespace Bitrix\Rest\V3\Exceptions;

class UnknownFilterOperatorException extends RestException
{
	public function __construct(
		protected string $operator,
	) {
		parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_UNKNOWNFILTEROPERATOREXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#OPERATOR#' => $this->operator,
		];
	}
}
