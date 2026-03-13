<?php

namespace Bitrix\Rest\V3\Exception;

class InvalidOrderException extends RestException
{
	public function __construct(
		protected mixed $order,
	) {
		parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_INVALIDORDEREXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#ORDER#' => is_string($this->order) ? $this->order : json_encode($this->order),
		];
	}
}
