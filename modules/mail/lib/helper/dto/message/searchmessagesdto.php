<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Dto\Message;

use Bitrix\Mail\Internal\Service\DateTime\DateTimeParser;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Main\Type\DateTime;

class SearchMessagesDto
{
	public const DEFAULT_LIMIT = 25;

	public const ALLOWED_BINDINGS = [
		MessageAccessTable::ENTITY_TYPE_NO_BIND,
		MessageAccessTable::ENTITY_TYPE_TASKS_TASK,
		MessageAccessTable::ENTITY_TYPE_CRM_ACTIVITY,
		MessageAccessTable::ENTITY_TYPE_BLOG_POST,
		MessageAccessTable::ENTITY_TYPE_IM_CHAT,
		MessageAccessTable::ENTITY_TYPE_CALENDAR_EVENT,
	];

	public const ALLOWED_EXCLUDE_BINDINGS = [
		MessageAccessTable::ENTITY_TYPE_TASKS_TASK,
		MessageAccessTable::ENTITY_TYPE_CRM_ACTIVITY,
		MessageAccessTable::ENTITY_TYPE_BLOG_POST,
		MessageAccessTable::ENTITY_TYPE_IM_CHAT,
		MessageAccessTable::ENTITY_TYPE_CALENDAR_EVENT,
	];

	/**
	 * @param string[]|null $bindings Allowlisted entity types from {@see self::ALLOWED_BINDINGS}.
	 *   When non-null, only messages with at least one binding of these types are returned.
	 * @param string[]|null $excludeBindings Allowlisted entity types from {@see self::ALLOWED_EXCLUDE_BINDINGS}.
	 *   When non-null, only messages without any binding of these types are returned (anti-join).
	 */
	public function __construct(
		public readonly ?int $mailboxId = null,
		public readonly ?string $searchQuery = null,
		public readonly ?DateTime $dateFrom = null,
		public readonly ?DateTime $dateTo = null,
		public readonly ?bool $isSeen = null,
		public readonly ?bool $hasAttachments = null,
		public readonly ?string $folder = null,
		public readonly ?array $bindings = null,
		public readonly ?array $excludeBindings = null,
		public readonly int $limit = self::DEFAULT_LIMIT,
		public readonly int $offset = 0,
	)
	{
	}

	/**
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function fromArray(array $props): self
	{
		$dateFrom = DateTimeParser::getNullableLowerBound($props, 'dateFrom');
		$dateTo = DateTimeParser::getNullableUpperBound($props, 'dateTo');
		DateTimeParser::validateRange($dateFrom, $dateTo);

		return new self(
			mailboxId: self::getInt($props, 'mailboxId'),
			searchQuery: self::getString($props, 'searchQuery'),
			dateFrom: $dateFrom,
			dateTo: $dateTo,
			isSeen: self::getBool($props, 'isSeen'),
			hasAttachments: self::getBool($props, 'hasAttachments'),
			folder: self::getString($props, 'folder'),
			bindings: self::normalizeBindings(
				self::filterAllowlistedStrings($props, 'bindings', self::ALLOWED_BINDINGS),
			),
			excludeBindings: self::filterAllowlistedStrings(
				$props,
				'excludeBindings',
				self::ALLOWED_EXCLUDE_BINDINGS,
			),
			limit: self::getInt($props, 'limit') ?? self::DEFAULT_LIMIT,
			offset: max(0, self::getInt($props, 'offset') ?? 0),
		);
	}

	/**
	 * @param string[] $allowlist
	 * @return string[]|null
	 */
	private static function filterAllowlistedStrings(array $props, string $key, array $allowlist): ?array
	{
		if (!isset($props[$key]) || !is_array($props[$key]))
		{
			return null;
		}

		$normalized = [];
		foreach ($props[$key] as $value)
		{
			if (!is_string($value) || !in_array($value, $allowlist, true))
			{
				continue;
			}
			$normalized[$value] = true;
		}

		if ($normalized === [])
		{
			return null;
		}

		return array_keys($normalized);
	}

	/**
	 * "No bind" is mutually exclusive with specific binding types.
	 *
	 * @param string[]|null $bindings
	 * @return string[]|null
	 */
	private static function normalizeBindings(?array $bindings): ?array
	{
		if ($bindings === null)
		{
			return null;
		}

		if (in_array(MessageAccessTable::ENTITY_TYPE_NO_BIND, $bindings, true))
		{
			return [MessageAccessTable::ENTITY_TYPE_NO_BIND];
		}

		return $bindings;
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
