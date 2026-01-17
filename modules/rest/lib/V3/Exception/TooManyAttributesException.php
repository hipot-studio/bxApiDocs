<?php

namespace Bitrix\Rest\V3\Exception;

class TooManyAttributesException extends RestException
{
	public function __construct(
		public string $class,
		public string $attribute,
		public int $expectedCount,
	) {
		return parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_TOOMANYATTRIBUTESEXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#CLASS#' => (new \ReflectionClass($this->class))->getShortName(),
			'#ATTRIBUTE#' => (new \ReflectionClass($this->attribute))->getShortName(),
			'#EXPECTED_COUNT#' => $this->expectedCount,
		];
	}
}
