<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\MailTemplate;

use Bitrix\Crm\MailTemplate\MailTemplateAccess;
use Bitrix\Crm\Service\Container;

class MailTemplateEditorAccess
{
	public function hasCrmAccess(int $userId): bool
	{
		return $userId > 0
			&& Container::getInstance()->getUserPermissions($userId)->entityType()->canReadSomeItemsInCrm();
	}

	public function canRead(array $template, int $userId): bool
	{
		if (!$this->hasCrmAccess($userId))
		{
			return false;
		}

		return $this->isAdminOrOwner($template, $userId)
			|| (int)$template['SCOPE'] === \CCrmMailTemplateScope::Common
			|| (
				(int)$template['SCOPE'] === \CCrmMailTemplateScope::Limited
				&& MailTemplateAccess::checkAccessToLimitedTemplate((int)$template['ID'], $userId)
			);
	}

	public function canEdit(array $template, int $userId): bool
	{
		if (!$this->hasCrmAccess($userId))
		{
			return false;
		}

		if ($this->isAdminOrOwner($template, $userId))
		{
			return true;
		}

		return (int)$template['SCOPE'] === \CCrmMailTemplateScope::Common
			&& (bool)\CAccess::getUserCodes($userId, [
				'PROVIDER_ID' => 'intranet',
				'ACCESS_CODE' => sprintf('IU%u', $template['OWNER_ID']),
			])->fetch();
	}

	private function isAdminOrOwner(array $template, int $userId): bool
	{
		return (int)$template['OWNER_ID'] === $userId
			|| Container::getInstance()->getUserPermissions($userId)->isAdmin();
	}
}
