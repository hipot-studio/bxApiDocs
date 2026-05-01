<?php

namespace Bitrix\Rest\Internal\Entity\SystemUser;

enum AccountType: string
{
	case AUTO = 'AUTO';
	case MANUAL = 'MANUAL';
}
