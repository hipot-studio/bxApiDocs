<?php

namespace Bitrix\Rest\V3\Exception;

class EntityAlreadyExistsException extends RestException
{
	public function __construct(
		protected string $id,
	) {
		parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_ENTITYALREADYEXISTSEXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#ID#' => $this->id,
		];
	}
}
