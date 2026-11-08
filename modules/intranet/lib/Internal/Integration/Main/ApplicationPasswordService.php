<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Main\Authentication\ApplicationPasswordTable;

class ApplicationPasswordService
{
	private const DESKTOP_APPLICATION_ID = 'desktop';
	private const MOBILE_APPLICATION_ID = 'mobile';

	public function removeAllByUserId(int $userId): void
	{
		$this->deleteByFilter([
			"=USER_ID" => $userId,
			"=APPLICATION_ID" => [self::DESKTOP_APPLICATION_ID, self::MOBILE_APPLICATION_ID],
		]);
	}

	public function removeAllByUserIdExceptDevice(int $userId, string $deviceCode): void
	{
		$this->deleteByFilter([
			"=USER_ID" => $userId,
			"=APPLICATION_ID" => [self::DESKTOP_APPLICATION_ID, self::MOBILE_APPLICATION_ID],
			"!=CODE" => $deviceCode,
		]);
	}

	public function removeAllByUserIdExceptPasswordId(int $userId, int $passwordId): void
	{
		$this->deleteByFilter([
			"=USER_ID" => $userId,
			"=APPLICATION_ID" => [self::DESKTOP_APPLICATION_ID, self::MOBILE_APPLICATION_ID],
			"!=ID" => $passwordId,
		]);
	}

	private function deleteByFilter(array $filter): void
	{
		$passwordsList = ApplicationPasswordTable::getList([
			"filter" => $filter,
		]);

		while ($password = $passwordsList->fetchObject())
		{
			$password->delete();
		}
	}
}
