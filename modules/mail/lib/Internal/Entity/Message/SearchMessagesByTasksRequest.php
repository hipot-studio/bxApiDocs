<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\Message;

use Bitrix\Mail\Internal\Service\DateTime\DateTimeParser;
use Bitrix\Main\Type\DateTime;

class SearchMessagesByTasksRequest
{
	public const TASK_STATE_OPEN = 'open';
	public const TASK_STATE_CLOSED = 'closed';

	public function __construct(
		public readonly int $limit,
		public readonly int $offset = 0,
		public readonly ?string $taskState = null,
		public readonly ?bool $taskOverdued = null,
		public readonly ?DateTime $taskCreatedFrom = null,
		public readonly ?DateTime $taskCreatedTo = null,
		public readonly ?int $taskResponsibleId = null,
	)
	{
	}

	/**
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function fromArray(array $props): self
	{
		$state = self::getString($props, 'taskState');
		if ($state !== null && !in_array($state, [self::TASK_STATE_OPEN, self::TASK_STATE_CLOSED], true))
		{
			$state = null;
		}

		$taskCreatedFrom = DateTimeParser::getNullableLowerBound($props, 'taskCreatedFrom');
		$taskCreatedTo = DateTimeParser::getNullableUpperBound($props, 'taskCreatedTo');
		DateTimeParser::validateRange($taskCreatedFrom, $taskCreatedTo, 'taskCreatedFrom', 'taskCreatedTo');

		return new self(
			limit: self::getInt($props, 'limit') ?? 0,
			offset: max(0, self::getInt($props, 'offset') ?? 0),
			taskState: $state,
			taskOverdued: self::getBool($props, 'taskOverdued'),
			taskCreatedFrom: $taskCreatedFrom,
			taskCreatedTo: $taskCreatedTo,
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
}
