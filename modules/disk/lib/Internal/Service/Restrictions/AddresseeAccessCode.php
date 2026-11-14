<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Restrictions;

use Bitrix\Disk\Internal\Entity\FormatPolicyRule;
use Bitrix\Main\ArgumentException;

/**
 * Access code of the addressee of a private format rule. The code is what the rule is matched by
 * against the codes of a user, so the hierarchy and the membership are left to the codes themselves.
 */
final class AddresseeAccessCode
{
	public const LEVEL_USER = 'user';
	public const LEVEL_TEAM = 'team';
	public const LEVEL_DEPARTMENT = 'department';

	/**
	 * @param string $type One of FormatPolicyRule::ENTITY_TYPE_* constants.
	 * @param int $id Id of the employee, of the section of the intranet structure or of the team node.
	 * @param bool $includeNested Whether the rule covers the nested departments or teams too.
	 * @return string
	 * @throws ArgumentException When the addressee has no access code of its own.
	 */
	public static function build(string $type, int $id, bool $includeNested): string
	{
		if ($id <= 0)
		{
			throw new ArgumentException('Addressee id must be positive', 'id');
		}

		$prefix = match ($type)
		{
			FormatPolicyRule::ENTITY_TYPE_USER => 'U',
			FormatPolicyRule::ENTITY_TYPE_TEAM => $includeNested ? 'SNTR' : 'SNT',
			FormatPolicyRule::ENTITY_TYPE_DEPARTMENT => $includeNested ? 'DR' : 'D',
			default => throw new ArgumentException('Unknown addressee type', 'type'),
		};

		return $prefix . $id;
	}

	/**
	 * Returns the level of a rule addressed by this code, or null when the code addresses no rule:
	 * a foreign code, a code of a department node of the HR structure, a team code on a portal without
	 * humanresources or a department code on a portal without departments.
	 *
	 * @return string|null One of LEVEL_* constants.
	 */
	public static function levelOf(string $code, bool $departmentsAvailable, bool $teamsAvailable): ?string
	{
		if (preg_match('/^U\d+$/', $code))
		{
			return self::LEVEL_USER;
		}

		if ($teamsAvailable && preg_match('/^SNTR?\d+$/', $code))
		{
			return self::LEVEL_TEAM;
		}

		if ($departmentsAvailable && preg_match('/^DR?\d+$/', $code))
		{
			return self::LEVEL_DEPARTMENT;
		}

		return null;
	}
}
