<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum;

use Bitrix\Disk\User;

enum SharingMemberType: string
{
	case Employee = 'employee';
	case Extranet = 'extranet';
	case Collaber = 'collaber';
	case Group = 'group';
	case Department = 'department';

	/**
	 * An unrecognized user must not pass for an employee: Employee and Collaber are only handed out
	 * on a positive trait. Extranet is the remaining bucket, since User::isExtranetUser() means
	 * "not an employee, extranet module installed" — so null is reached only without that module.
	 */
	public static function forUser(User $user): ?self
	{
		if ($user->isCollaber())
		{
			return self::Collaber;
		}

		if ($user->isIntranetUser())
		{
			return self::Employee;
		}

		if ($user->isExtranetUser())
		{
			return self::Extranet;
		}

		return null;
	}

	/**
	 * @return array
	 */
	public static function forExtension(): array
	{
		return [
			'employee' => SharingMemberType::Employee->value,
			'extranet' => SharingMemberType::Extranet->value,
			'collaber' => SharingMemberType::Collaber->value,
			'group' => SharingMemberType::Group->value,
			'department' => SharingMemberType::Department->value,
		];
	}
}
