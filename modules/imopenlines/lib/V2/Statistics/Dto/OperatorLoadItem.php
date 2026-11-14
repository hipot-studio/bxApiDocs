<?php

namespace Bitrix\ImOpenLines\V2\Statistics\Dto;

use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\Main\Type\DateTime;

/**
 * Read-only REST representation of a single operator's load/status for
 * imopenlines.v2.Operator.list.
 *
 * Built from a QueueTable row (CONFIG_ID, USER_ID, LAST_ACTIVITY_DATE) plus the
 * per-row MAX_CHAT (config), pause flag (user option) and a runtime active-sessions
 * count. There is intentionally no `pauseDate` field: the pause storage
 * (UserOptionTable.PAUSE) is a global boolean per user with no persisted start
 * timestamp, so a pause-start date cannot be derived (see phase 3 decision).
 */
final class OperatorLoadItem implements RestConvertible
{
	public const STATUS_ONLINE = 'online';
	public const STATUS_OFFLINE = 'offline';
	public const STATUS_PAUSE = 'pause';

	public function __construct(
		private readonly int $userId,
		private readonly int $configId,
		private readonly string $status,
		private readonly int $activeSessions,
		private readonly int $maxChat,
		private readonly mixed $lastActivityDate,
	)
	{
	}

	public static function getRestEntityName(): string
	{
		return 'operatorLoad';
	}

	public function toRestFormat(array $option = []): array
	{
		$freeSlots = $this->maxChat - $this->activeSessions;

		return [
			'userId' => $this->userId,
			'configId' => $this->configId,
			'status' => $this->status,
			'activeSessions' => $this->activeSessions,
			'maxChat' => $this->maxChat,
			'freeSlots' => max(0, $freeSlots),
			'lastActivityDate' => $this->formatDate($this->lastActivityDate),
		];
	}

	private function formatDate(mixed $date): ?string
	{
		if ($date === null || $date === '')
		{
			return null;
		}

		if ($date instanceof DateTime)
		{
			return $date->format('c');
		}

		return (string)$date;
	}
}
