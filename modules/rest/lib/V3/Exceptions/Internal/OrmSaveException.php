<?php

namespace Bitrix\Rest\V3\Exceptions\Internal;

class OrmSaveException extends InternalException
{
	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_ORMSAVEEXCEPTION';
	}
}
