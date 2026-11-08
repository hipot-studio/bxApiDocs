<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Repository;

use Bitrix\Main\Config\Option;

class MandatoryOtpAuditRepository
{
	private const MODULE_ID = 'intranet';
	private const DISABLED_BY_OPTION = 'mandatory_otp_disabled_by';

	public function saveDisabling(int $userId, int $timestamp): void
	{
		Option::set(
			self::MODULE_ID,
			self::DISABLED_BY_OPTION,
			(string)json_encode([
				'userId' => $userId,
				'timestamp' => $timestamp,
			]),
		);
	}

	public function clearDisabling(): void
	{
		Option::delete(self::MODULE_ID, ['name' => self::DISABLED_BY_OPTION]);
	}

	/**
	 * @return array{userId: int, timestamp: int}|null
	 */
	public function getLastDisabling(): ?array
	{
		$storedValue = (string)Option::get(self::MODULE_ID, self::DISABLED_BY_OPTION, '');

		if ($storedValue === '')
		{
			return null;
		}

		$record = json_decode($storedValue, true);

		if (!is_array($record))
		{
			return null;
		}

		$userId = (int)($record['userId'] ?? 0);
		$timestamp = (int)($record['timestamp'] ?? 0);

		if ($userId <= 0 || $timestamp <= 0)
		{
			return null;
		}

		return [
			'userId' => $userId,
			'timestamp' => $timestamp,
		];
	}
}
