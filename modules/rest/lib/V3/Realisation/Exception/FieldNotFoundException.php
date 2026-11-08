<?php

namespace Bitrix\Rest\V3\Realisation\Exception;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

use CRestServer;

class FieldNotFoundException extends RestException implements SkipWriteToLogException
{
	protected const STATUS = CRestServer::STATUS_NOT_FOUND;

	public function __construct(protected string $field)
	{
		parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_REALISATION_EXCEPTION_FIELDNOTFOUNDEXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#FIELD#' => $this->field,
		];
	}
}
