<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

/**
 * Transport-neutral resolution of the bizproc-admin flag: the subject is always explicit by userId, never read from
 * the ambient session (CBPWorkflowTemplateUser::CurrentUser, CBitrix24::IsPortalAdmin and the like). On the Marta
 * entry the ambient subject is not guaranteed to equal the authorized user, and any session-dependent disjunct would
 * answer differently for the same userId with and without a session, silently breaking the validate == push == Marta
 * invariant. The formula covers the admin group and the bizproc template admins declared on top of it.
 */
final class UserAdminResolver
{
	public static function isAdmin(int $userId): bool
	{
		return (new \CBPWorkflowTemplateUser($userId))->isAdmin();
	}
}
