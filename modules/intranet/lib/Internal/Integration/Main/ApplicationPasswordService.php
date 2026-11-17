<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Main\Authentication\ApplicationPasswordTable;
use Bitrix\Main\ORM\Fields\ExpressionField;

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

	public function hasMobilePasswordByCode(int $userId, string $code): bool
	{
		$normalizedCode = $this->normalizeCode($code);

		if ($normalizedCode === '')
		{
			return false;
		}

		$password = ApplicationPasswordTable::query()
			->setSelect(['ID'])
			->registerRuntimeField(new ExpressionField('NORMALIZED_CODE', 'UPPER(TRIM(%s))', 'CODE'))
			->where('USER_ID', $userId)
			->where('APPLICATION_ID', self::MOBILE_APPLICATION_ID)
			->where('NORMALIZED_CODE', $normalizedCode)
			->setLimit(1)
			->fetch()
		;

		return is_array($password);
	}

	public function removeAllByUserIdExceptPasswordId(int $userId, int $passwordId): void
	{
		$this->deleteByFilter([
			"=USER_ID" => $userId,
			"=APPLICATION_ID" => [self::DESKTOP_APPLICATION_ID, self::MOBILE_APPLICATION_ID],
			"!=ID" => $passwordId,
		]);
	}

	private function normalizeCode(string $code): string
	{
		return mb_strtoupper(trim($code));
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
