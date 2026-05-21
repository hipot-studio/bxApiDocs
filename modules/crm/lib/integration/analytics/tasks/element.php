<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Analytics\Tasks;

enum Element: string
{
	case CompleteButton = 'complete_button';
	case Auto = 'auto';
}
