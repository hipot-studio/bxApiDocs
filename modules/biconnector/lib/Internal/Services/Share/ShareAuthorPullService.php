<?php

namespace Bitrix\BIConnector\Internal\Services\Share;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardShare;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;

/**
 * Tells the open share popups of link authors that a controller switched their links off,
 * so the popup stops saving and shows the switch-off. The controller's own links are skipped.
 */
class ShareAuthorPullService
{
	public const COMMAND_DISABLED_BY_ADMIN = 'shareDisabledByAdmin';

	/**
	 * @param SupersetDashboardShare[] $shares
	 */
	public function sendDisabledByAdmin(int $controllerId, array $shares, bool $isBanned): void
	{
		$shares = array_filter(
			$shares,
			static fn(SupersetDashboardShare $share) => $share->getCreatedById() !== $controllerId,
		);
		if (empty($shares) || !$this->isPullAvailable())
		{
			return;
		}

		$disabledByAdmin = [
			'id' => $controllerId,
			'name' => $this->getUserName($controllerId),
			'dateTimestamp' => time(),
		];

		foreach ($shares as $share)
		{
			$this->send([$share->getCreatedById()], [
				'module_id' => 'biconnector',
				'command' => self::COMMAND_DISABLED_BY_ADMIN,
				'params' => [
					'dashboardId' => $share->getDashboardId(),
					'shareId' => $share->getId(),
					'isBanned' => $isBanned,
					'disabledByAdmin' => $disabledByAdmin,
				],
			]);
		}
	}

	protected function isPullAvailable(): bool
	{
		return Loader::includeModule('pull');
	}

	/**
	 * @param int[] $userIds
	 */
	protected function send(array $userIds, array $message): void
	{
		\Bitrix\Pull\Event::add($userIds, $message);
	}

	private function getUserName(int $userId): string
	{
		$user = UserTable::getRow([
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'EMAIL'],
			'filter' => ['=ID' => $userId],
		]);

		return $user ? \CUser::FormatName(\CSite::GetNameFormat(false), $user, true, false) : '';
	}
}
