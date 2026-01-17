<?php

namespace Bitrix\Rest\V3\Exceptions;

class ClassRequireAttributeException extends RestException
{
	public function __construct(
		public string $class,
		public string $attribute,
	) {
		parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_CLASSREQUIREATTIBUTEEXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#CLASS#' => (new \ReflectionClass($this->class))->getShortName(),
			'#ATTRIBUTE#' => (new \ReflectionClass($this->attribute))->getShortName(),
		];
	}
}
