<?php

namespace Bitrix\Rest\V3\Exception;

class InvalidClassInstanceProvidedException extends RestException
{
	public function __construct(
		public string $provided,
		public string $required,
	) {
		return parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_INVALIDCLASSPROVIDEDEXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#PROVIDED#' => (new \ReflectionClass($this->provided))->getShortName(),
			'#REQUIRED#' => (new \ReflectionClass($this->required))->getShortName(),
		];
	}
}
