<?php

namespace Bitrix\Rest\V3\Exception;

class LicenseException extends RestException implements SkipWriteToLogException
{
	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_LICENCEEXCEPTION';
	}
}
