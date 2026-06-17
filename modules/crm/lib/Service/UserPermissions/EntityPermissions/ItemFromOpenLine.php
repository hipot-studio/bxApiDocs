<?php

namespace Bitrix\Crm\Service\UserPermissions\EntityPermissions;

use Bitrix\ImOpenLines\Model\EO_Session;
use Bitrix\ImOpenLines\Model\SessionTable;
use Bitrix\Main\Loader;
use CCrmActivity;

/**
 * @internal
 * Do not use directly, only through \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->itemFromOpenLines()
 */
final readonly class ItemFromOpenLine
{
	public function __construct(
		private int $userId,
	)
	{
	}

	public function hasAccessFromMcpTool(int $entityTypeId, int $entityId): bool
	{
		$latestSession = $this->getLatestUserSession();
		if ($latestSession === null)
		{
			return false;
		}

		$activityId = $latestSession->getCrmActivityId();
		if ($activityId <= 0)
		{
			return false;
		}

		$bindings = CCrmActivity::GetBindings($activityId);
		if (is_array($bindings))
		{
			foreach ($bindings as $binding)
			{
				$ownerTypeId = (int)($binding['OWNER_TYPE_ID'] ?? 0);
				$ownerId = (int)($binding['OWNER_ID'] ?? 0);
				if (
					$ownerTypeId === $entityTypeId
					&& $ownerId === $entityId
				)
				{
					return true;
				}
			}
		}

		return false;
	}

	private function getLatestUserSession(): ?EO_Session
	{
		if (!Loader::includeModule('imopenlines'))
		{
			return null;
		}

		return SessionTable::query()
			->setSelect([
				'ID',
				'CRM',
				'CRM_ACTIVITY_ID',
			])
			->where('CRM', 'Y')
			->where('USER_ID', $this->userId)
			->setOrder([
				'DATE_CREATE' => 'DESC'
			])
			->setLimit(1)
			->fetchObject()
		;
	}
}
