<?php

namespace Bitrix\Mail\Access\Role;

use Bitrix\Main\Access\Role\RoleDictionary as BaseRoleDictionary;

class RoleDictionary extends BaseRoleDictionary
{
	public const ROLE_ADMIN = 'MAIL_ROLE_ADMIN';
	public const ROLE_DIRECTOR = 'MAIL_ROLE_DIRECTOR';
	public const ROLE_EMPLOYEE = 'MAIL_ROLE_EMPLOYEE';
}
