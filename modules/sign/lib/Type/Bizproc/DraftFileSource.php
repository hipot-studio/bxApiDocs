<?php

namespace Bitrix\Sign\Type\Bizproc;

/**
 * Where the draft activity takes the document file from.
 * The values are written by the canonical bizproc file control, they are an external contract.
 *
 * @see bizproc/install/js/bizproc/automation/src/selectors/file-selector.js
 */
enum DraftFileSource: string
{
	case FILE = 'file';
	case DISK = 'disk';
}
