<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

class ApplicationNotFoundException extends RestException implements SkipWriteToLogException
{
	public function __construct(protected string $clientId)
	{
		parent::__construct();
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_REST_APPLICATION_NOT_FOUND_EXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#CLIENT_ID#' => $this->clientId,
		];
	}
}
