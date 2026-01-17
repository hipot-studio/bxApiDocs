<?php

namespace Bitrix\Rest\V3\Exception\Internal;

class OrmSaveException extends InternalException
{
	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_ORMSAVEEXCEPTION';
	}
}
