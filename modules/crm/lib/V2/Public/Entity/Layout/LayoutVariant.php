<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Layout;

enum LayoutVariant: string
{
	case General = 'general';
	case Returning = 'returning';
}
