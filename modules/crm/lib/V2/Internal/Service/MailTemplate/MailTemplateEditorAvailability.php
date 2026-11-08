<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\MailTemplate;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Config\Option;
use Bitrix\Main\SiteTable;

class MailTemplateEditorAvailability
{
	public function isAvailable(int $userId): bool
	{
		return $userId > 0
			&& $this->getOption('crm', 'mail_template_editor_v2_enabled', 'N') === 'Y'
			&& $this->hasCrmAccess($userId);
	}

	public function isValidSiteId(string $siteId): bool
	{
		if ($siteId === '')
		{
			return false;
		}

		$site = $this->getSite($siteId);

		return $site !== null && $site['LID'] === $siteId && $site['ACTIVE'] === 'Y';
	}

	protected function getOption(string $moduleId, string $name, string $default): string
	{
		return Option::get($moduleId, $name, $default);
	}

	protected function hasCrmAccess(int $userId): bool
	{
		return Container::getInstance()->getUserPermissions($userId)->entityType()->canReadSomeItemsInCrm();
	}

	protected function getSite(string $siteId): ?array
	{
		return SiteTable::getByPrimary($siteId, ['select' => ['LID', 'ACTIVE']])->fetch() ?: null;
	}
}
