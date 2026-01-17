<?php

namespace Bitrix\Rest\V3\Exception;

class LicenseException extends RestException
{
	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_LICENCEEXCEPTION';
	}
}
