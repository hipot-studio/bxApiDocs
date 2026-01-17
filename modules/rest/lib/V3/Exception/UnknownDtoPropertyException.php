<?php

namespace Bitrix\Rest\V3\Exception;

class UnknownDtoPropertyException extends RestException
{
	public function __construct(
		public string $dtoShortName,
		public string $propertyName,
	) {
		return parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_UNKNOWNDTOPROPERTYEXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#DTO#' => $this->dtoShortName,
			'#FIELD#' => $this->propertyName,
		];
	}
}
