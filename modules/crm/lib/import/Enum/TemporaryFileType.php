<?php

namespace Bitrix\Crm\Import\Enum;

enum TemporaryFileType: string
{
	case Import = 'import';
	case Error = 'error';
	case Duplicate = 'duplicate';
}
