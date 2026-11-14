<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Activity;

use Bitrix\Rest\Infrastructure\Controller\ActionFilter\AdminUser;
use Bitrix\Rest\V3\Exception\AccessDeniedException;

/**
 * The list of activities is refused to a user who is not an administrator of the portal.
 *
 * It is the access denied of REST, with its status, a code of its own, so a client tells this refusal from the one
 * of a method that is not published, and the phrase REST gives for a method of administrators.
 */
final class ActivityListAdminOnlyException extends AccessDeniedException
{
	public const CODE = 'CRM_ACTIVITY_LIST_ADMIN_ONLY';

	public function getRegistryCode(): string
	{
		return self::CODE;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_INFRASTRUCTURE_CONTROLLER_ACTION_FILTER_ADMIN_USER';
	}

	protected function getClassWithPhrase(): string
	{
		return AdminUser::class;
	}
}
