<?php

namespace Bitrix\Rest\Internal\Entity\SystemUser;

enum ResourceType: string
{
	case APPLICATION = 'APP';
	case WEBHOOK = 'WEBHOOK';
}
