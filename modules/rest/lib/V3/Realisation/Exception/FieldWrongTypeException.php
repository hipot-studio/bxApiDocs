<?php

namespace Bitrix\Rest\V3\Realisation\Exception;

use Bitrix\Rest\V3\Exception\RestException;
use CRestServer;

class FieldWrongTypeException extends RestException
{
	protected const STATUS = CRestServer::STATUS_NOT_FOUND;

	public function __construct(protected string $fieldName, protected string $requestedType, protected string $actualType)
	{
		parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_REALISATION_EXCEPTION_FIELDWRONGTYPEEXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#FIELD_NAME#' => $this->fieldName,
			'#REQUESTED_TYPE#' => $this->requestedType,
			'#ACTUAL_TYPE#' => $this->actualType
		];
	}
}

