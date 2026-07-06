<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

class ApplicationNotInstalledException extends RestException implements SkipWriteToLogException
{
	protected function getMessagePhraseCode(): string
	{
		return 'REST_REST_APPLICATION_NOT_INSTALLED_EXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#ERRORS#' => ($this->getPrevious()?->getMessage() ?? 'unknown error'),
		];
	}
}
