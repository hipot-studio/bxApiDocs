<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Provider;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Main\Loader;

/**
 * Rights of the user setting a digest up, as opposed to {@see DashboardViewRightChecker}, which answers for
 * a recipient on sending.
 * Left open for extension so tests can replace it with a stub.
 */
class DigestSetupRightChecker
{
	public function canViewDashboard(int $userId, Model\Dashboard $dashboard): bool
	{
		return AccessController::getInstance($userId)->check(
			ActionDictionary::ACTION_BIC_DASHBOARD_VIEW,
			DashboardAccessItem::createFromEntity($dashboard),
		);
	}

	/**
	 * A digest is an AI agent of the workflow module, so the setter needs the right to create one.
	 */
	public function canCreateAgent(int $userId): bool
	{
		return $userId > 0
			&& Loader::includeModule('bizproc')
			&& (new TemplateAccessService())->canCreate($userId)
		;
	}

	/**
	 * Who holds the right to manage BI rights may see how many people of a department lack access to a report.
	 */
	public function canManageRights(int $userId): bool
	{
		return AccessController::getInstance($userId)->check(ActionDictionary::ACTION_BIC_SETTINGS_EDIT_RIGHTS);
	}
}
