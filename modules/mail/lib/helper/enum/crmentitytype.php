<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Enum;

enum CrmEntityType: string
{
	case Lead = 'LEAD';
	case Contact = 'CONTACT';
}
