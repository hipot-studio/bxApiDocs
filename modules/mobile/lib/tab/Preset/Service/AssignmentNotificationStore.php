<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;

class AssignmentNotificationStore
{
	private readonly Connection $connection;

	public function __construct(?Connection $connection = null)
	{
		$this->connection = $connection ?? Application::getConnection();
	}

	public function get(int $userId, string $siteId): ?string
	{
		$row = \CUserOptions::GetList([], [
			'USER_ID' => $userId,
			'CATEGORY' => 'mobile',
			'NAME' => $this->getOptionName($siteId),
			'COMMON' => 'N',
		])->Fetch();
		$value = $row ? unserialize($row['VALUE'], ['allowed_classes' => false]) : null;

		return is_string($value) ? $value : null;
	}

	public function set(int $userId, string $siteId, string $token): bool
	{
		return \CUserOptions::SetOption('mobile', $this->getOptionName($siteId), $token, false, $userId);
	}

	public function lock(int $userId, string $siteId): bool
	{
		return $this->connection->lock($this->getLockName($userId, $siteId), 0);
	}

	public function unlock(int $userId, string $siteId): void
	{
		$this->connection->unlock($this->getLockName($userId, $siteId));
	}

	private function getOptionName(string $siteId): string
	{
		return 'tab_preset_assignment_seen_' . $siteId;
	}

	private function getLockName(int $userId, string $siteId): string
	{
		return 'mobile_preset_ack_' . hash('sha256', $userId . ':' . $siteId);
	}
}
