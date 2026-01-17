<?php

namespace Bitrix\Rest\V3\Exceptions;

class EntityNotFoundException extends RestException
{
	public function __construct(
		protected int $id,
	) {
		parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_ENTITYNOTFOUNDEXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#ID#' => $this->id,
		];
	}
}
