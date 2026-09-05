<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller\ActionFilter;

use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\Service\SharedSignature\SharedSignatureService;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;

/**
 * Gate filter for endpoints that operate on signatures of the shared scope only.
 * Denies access (403) if:
 *   - the mailbox-management feature is disabled (tariff/license), OR
 *   - the current user may not manage employee mailboxes (RBAC).
 *
 * The same pair of checks is exposed as SharedSignatureService::canManageSharedScope() and is
 * applied per item by the unified controller, where an owner-scope signature must stay
 * reachable for its owner regardless of the tariff.
 */
final class SharedSignatureAccess extends Base
{
	public const ERROR_LICENSE_DENIED = 'SHARED_SIGNATURE_LICENSE_DENIED';
	public const ERROR_ACCESS_DENIED = SharedSignatureService::ERROR_ACCESS_DENIED;

	public function onBeforeAction(Event $event)
	{
		if (!LicenseManager::isMailboxManagementEnabled())
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->addError(new Error(
				Loc::getMessage('MAIL_SIGNATURE_LICENSE_DENIED'),
				self::ERROR_LICENSE_DENIED,
			));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		if (!MailAccess::hasCurrentUserAccessToMailboxManagement())
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->addError(SharedSignatureService::accessDeniedError());

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
