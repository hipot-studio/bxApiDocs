<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Layout;

enum LayoutMode: string
{
	case Create = 'create';
	case Update = 'update';
}
