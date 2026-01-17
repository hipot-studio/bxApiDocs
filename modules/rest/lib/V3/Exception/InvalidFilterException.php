<?php

namespace Bitrix\Rest\V3\Exception;

class InvalidFilterException extends RestException
{
	public function __construct(
		protected mixed $filter,
	) {
		parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_INVALIDFILTEREXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#FILTER#' => is_string($this->filter) ? $this->filter : json_encode($this->filter),
		];
	}
}
