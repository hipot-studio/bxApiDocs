<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Section;

use Bitrix\Tasks\Internals\Task\Template\TemplateMemberTable;

/**
 * Roles of the "Regular tasks" section role slice (ALG-S3).
 *
 * The semantic filter keys (sent by the frontend) map to the membership types
 * `b_tasks_template_member.TYPE` ({@see TemplateMemberTable::MEMBER_TYPE_*}):
 *   originator -> ORIGINATOR (O), responsible -> RESPONSIBLE (R),
 *   accomplice -> ACCOMPLICE (A), auditor -> AUDITOR (U).
 * "All roles" (`all` / empty) - no role condition.
 */
enum SectionRole: string
{
	case Originator = 'originator';
	case Responsible = 'responsible';
	case Accomplice = 'accomplice';
	case Auditor = 'auditor';

	/**
	 * Membership type for the ORM condition, or null for "All roles" / an unknown key.
	 */
	public static function resolveMemberType(?string $roleId): ?string
	{
		if ($roleId === null || $roleId === '' || $roleId === 'all')
		{
			return null;
		}

		$role = self::tryFrom($roleId);
		if ($role === null)
		{
			return null;
		}

		return match ($role)
		{
			self::Originator => TemplateMemberTable::MEMBER_TYPE_ORIGINATOR,
			self::Responsible => TemplateMemberTable::MEMBER_TYPE_RESPONSIBLE,
			self::Accomplice => TemplateMemberTable::MEMBER_TYPE_ACCOMPLICE,
			self::Auditor => TemplateMemberTable::MEMBER_TYPE_AUDITOR,
		};
	}
}
