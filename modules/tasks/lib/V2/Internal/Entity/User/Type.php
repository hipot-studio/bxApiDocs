<?php

namespace Bitrix\Tasks\V2\Internal\Entity\User;

enum Type: string
{
	case Employee = 'employee';
	case Extranet = 'extranet';
	case Collaber = 'collaber';
}
