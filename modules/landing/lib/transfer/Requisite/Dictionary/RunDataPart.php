<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Requisite\Dictionary;

enum RunDataPart: string
{
	case OldId = 'oldId';
	case NewId = 'newId';
	case AdditionalFieldsBefore = 'additionalFieldsBefore';
	case NeedConvertFoldersOldFormat = 'needConvertFoldersOldFormat';
	case PreviousTplCode = 'previousTplCode';
}
