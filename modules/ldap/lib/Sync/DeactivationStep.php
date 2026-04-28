<?php

namespace Bitrix\Ldap\Sync;

use Bitrix\Ldap\Internal\Models\UserLastSyncTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UserTable;

class DeactivationStep
{
	public const DEACTIVATE_USERS_COUNT_PER_STEP = 100;

	protected int $candidatesCount = 0;

	protected int $deactivatedCount = 0;

	public function getCandidatesCount(): int
	{
		return $this->candidatesCount;
	}

	public function getDeactivatedCount(): int
	{
		return $this->deactivatedCount;
	}

	public function execute(Session $session): void
	{
		$this->candidatesCount = 0;
		$this->deactivatedCount = 0;

		// deactivation part 1 - find active users with no sync records
		$this->deactivateByQuery(UserTable::query()
			->setSelect([
				'ID',
				'SERVER_ID' => 'LAST_SYNC.SERVER_ID',
				'LAST_SYNC_AT' => 'LAST_SYNC.LAST_SYNC_AT',
			])
			->registerRuntimeField(new Reference(
				'LAST_SYNC',
				UserLastSyncTable::class,
				Join::on('this.ID', 'ref.USER_ID'),
				['join_type' => Join::TYPE_LEFT]
			))
			->where('ACTIVE', true)
			->where('EXTERNAL_AUTH_ID', 'LDAP#' . $session->serverId)
			->whereNull('LAST_SYNC.SERVER_ID')
		);

		if ($this->candidatesCount > 0)
		{
			return;
		}

		// deactivation part 2 - find active users with outdated sync records
		$this->deactivateByQuery(UserTable::query()
			->setSelect([
				'ID',
				'SERVER_ID' => 'LAST_SYNC.SERVER_ID',
				'LAST_SYNC_AT' => 'LAST_SYNC.LAST_SYNC_AT',
			])
			->registerRuntimeField(new Reference(
				'LAST_SYNC',
				UserLastSyncTable::class,
				Join::on('this.ID', 'ref.USER_ID'),
				['join_type' => Join::TYPE_LEFT]
			))
			->where('ACTIVE', true)
			->where('EXTERNAL_AUTH_ID', 'LDAP#' . $session->serverId)
			->where('LAST_SYNC.SERVER_ID', $session->serverId)
			->where('LAST_SYNC.LAST_SYNC_AT', '<', $session->startedAt)
		);
	}

	protected function deactivateByQuery(Query $query): void
	{
		$tmpUser = new \CUser;
		$rows = $query->setLimit(self::DEACTIVATE_USERS_COUNT_PER_STEP)->exec();
		while ($row = $rows->fetch())
		{
			$this->candidatesCount++;
			if ($tmpUser->Update($row['ID'], ['ACTIVE' => 'N']))
			{
				$this->deactivatedCount++;
			}
		}
	}
}
