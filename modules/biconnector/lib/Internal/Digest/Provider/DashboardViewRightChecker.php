<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Provider;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Internal\Digest\Dto\DashboardSnapshotDto;

/**
 * Report view right of a named recipient: the digest runs unattended, so the
 * right is never taken from the current user.
 *
 * The access item is built from the snapshot the readiness check already holds:
 * building it from the id would read the report row a second time per recipient,
 * which is what {@see AccessController} warns about in its own comment.
 * Left open for extension so unit tests can replace it with a stub.
 */
class DashboardViewRightChecker
{
	public function canView(int $recipientId, DashboardSnapshotDto $dashboard): bool
	{
		return AccessController::getInstance($recipientId)->check(
			ActionDictionary::ACTION_BIC_DASHBOARD_VIEW,
			DashboardAccessItem::createFromArray([
				'ID' => $dashboard->id,
				'TYPE' => $dashboard->type,
				'STATUS' => $dashboard->status,
			]),
		);
	}
}
