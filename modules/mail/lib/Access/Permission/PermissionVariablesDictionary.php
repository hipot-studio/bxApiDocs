<?php

namespace Bitrix\Mail\Access\Permission;

use Bitrix\Main\Localization\Loc;

class PermissionVariablesDictionary
{
	public const VARIABLE_ALL = 30;
	public const VARIABLE_DEPARTMENT_WITH_SUBDEPARTMENTS = 20;
	public const VARIABLE_SELF_DEPARTMENTS = 10;
	public const VARIABLE_NONE = 0;

	/**
	 * @return list<array{id: int, title: string|null}>
	 */
	public static function getMailboxVariables(): array
	{
		return [
			[
				'id' => self::VARIABLE_ALL,
				'title' => Loc::getMessage('MAIL_ACCESS_RIGHTS_VARIABLES_ALL'),
			],
			[
				'id' => self::VARIABLE_DEPARTMENT_WITH_SUBDEPARTMENTS,
				'title' => Loc::getMessage('MAIL_ACCESS_RIGHTS_DEPARTMENT_WITH_SUBDEPARTMENTS'),
			],
			[
				'id' => self::VARIABLE_SELF_DEPARTMENTS,
				'title' => Loc::getMessage('MAIL_ACCESS_RIGHTS_SELF_DEPARTMENT'),
			],
			[
				'id' => self::VARIABLE_NONE,
				'title' => Loc::getMessage('MAIL_ACCESS_RIGHTS_VARIABLES_NONE'),
			],
		];
	}
}
