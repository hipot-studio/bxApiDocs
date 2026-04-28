<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Dto\Message;

use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;

class SearchMessagesDto
{
	private const DATE_FORMAT = 'Y/m/d H:i';
	public const DEFAULT_LIMIT = 25;

	public function __construct(
		public readonly ?int $mailboxId = null,
		public readonly ?string $searchQuery = null,
		public readonly ?DateTime $dateFrom = null,
		public readonly ?DateTime $dateTo = null,
		public readonly ?bool $isSeen = null,
		public readonly ?bool $hasAttachments = null,
		public readonly ?string $folder = null,
		public readonly int $limit = self::DEFAULT_LIMIT,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			mailboxId: self::getInt($props, 'mailboxId'),
			searchQuery: self::getString($props, 'searchQuery'),
			dateFrom: self::getDateTime($props, 'dateFrom'),
			dateTo: self::getDateTime($props, 'dateTo'),
			isSeen: self::getBool($props, 'isSeen'),
			hasAttachments: self::getBool($props, 'hasAttachments'),
			folder: self::getString($props, 'folder'),
			limit: self::getInt($props, 'limit') ?? self::DEFAULT_LIMIT,
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
