<?php

namespace Bitrix\Rest\V3\Exception;

class InvalidSelectException extends RestException implements SkipWriteToLogException
{
	public function __construct(
		protected mixed $select,
	) {
		parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_INVALIDSELECTEXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#SELECT#' => is_string($this->select) ? $this->select : json_encode($this->select),
		];
	}
}
