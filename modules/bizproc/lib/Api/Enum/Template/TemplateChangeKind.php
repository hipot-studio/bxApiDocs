<?php

namespace Bitrix\Bizproc\Api\Enum\Template;

enum TemplateChangeKind: string
{
	case Added = 'added';
	case Removed = 'removed';
	case Updated = 'updated';
	case Moved = 'moved';
}
