<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\Message;

use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;

class SearchMessagesByTasksRequest
{
	private const DATE_FORMAT = 'Y/m/d H:i';

	public const TASK_STATE_OPEN = 'open';
	public const TASK_STATE_CLOSED = 'closed';

	public function __construct(
		public readonly int $limit,
		public readonly ?string $taskState = null,
		public readonly ?bool $taskOverdued = null,
		public readonly ?DateTime $taskCreatedFrom = null,
		public readonly ?DateTime $taskCreatedTo = null,
		public readonly ?int $taskResponsibleId = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		$state = self::getString($props, 'taskState');
		if ($state !== null && !in_array($state, [self::TASK_STATE_OPEN, self::TASK_STATE_CLOSED], true))
		{
			$state = null;
		}

		return new self(
			limit: self::getInt($props, 'limit') ?? 0,
			taskState: $state,
			taskOverdued: self::getBool($props, 'taskOverdued'),
			taskCreatedFrom: self::getDateTime($props, 'taskCreatedFrom'),
			taskCreatedTo: self::getDateTime($props, 'taskCreatedTo'),
			taskResponsibleId: self::getInt($props, 'taskResponsibleId'),
		);
	}

	private static function getInt(array $props, string $key): ?int
	{
		if (!isset($props[$key]) || !is_numeric($props[$key]))
		{
			return null;
		}

		return (int)$props[$key];
	}

	private static function getString(array $props, string $key): ?string
	{
		if (!isset($props[$key]) || !is_string($props[$key]))
		{
			return null;
		}

		return $props[$key];
	}

	private static function getBool(array $props, string $key): ?bool
	{
		if (!isset($props[$key]) || !is_bool($props[$key]))
		{
			return null;
		}

		return $props[$key];
	}

	private static function getDateTime(array $props, string $key): ?DateTime
	{
		$value = self::getString($props, $key);
		if ($value === null)
		{
			return null;
		}

		try
		{
			return new DateTime($value, self::DATE_FORMAT);
		}
		catch (ObjectException)
		{
			return null;
		}
	}
}
