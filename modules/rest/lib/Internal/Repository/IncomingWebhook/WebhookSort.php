<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\IncomingWebhook;

use Bitrix\Rest\Internal\Repository\SortDirection;

/**
 * Sort criteria for incoming webhooks.
 *
 * Mirrors {@see \Bitrix\Rest\Internal\Repository\Application\AppSort}: accepts a raw
 * field => direction map, keeps only the whitelisted fields.
 */
final class WebhookSort
{
	private const FIELD_ID = 'ID';
	private const FIELD_TITLE = 'TITLE';
	private const FIELD_DATE_CREATE = 'DATE_CREATE';
	private const FIELD_DATE_LOGIN = 'DATE_LOGIN';

	/** @var array<string, string> */
	private array $sort = [];

	public function __construct(array $raw = [])
	{
		foreach ($raw as $field => $direction)
		{
			$dir = $this->normalizeDirection($direction);

			match ($field) {
				self::FIELD_ID => $this->byId($dir),
				self::FIELD_TITLE => $this->byTitle($dir),
				self::FIELD_DATE_CREATE => $this->byDateCreate($dir),
				self::FIELD_DATE_LOGIN => $this->byDateLogin($dir),
				default => null,
			};
		}
	}

	public function byId(SortDirection $direction = SortDirection::Asc): self
	{
		return $this->add(self::FIELD_ID, $direction);
	}

	public function byTitle(SortDirection $direction = SortDirection::Asc): self
	{
		return $this->add(self::FIELD_TITLE, $direction);
	}

	public function byDateCreate(SortDirection $direction = SortDirection::Asc): self
	{
		return $this->add(self::FIELD_DATE_CREATE, $direction);
	}

	public function byDateLogin(SortDirection $direction = SortDirection::Asc): self
	{
		return $this->add(self::FIELD_DATE_LOGIN, $direction);
	}

	public function isEmpty(): bool
	{
		return $this->sort === [];
	}

	/**
	 * @return array<string, string>
	 */
	public function toArray(): array
	{
		return $this->sort;
	}

	private function add(string $field, SortDirection $direction): self
	{
		$this->sort[$field] = $direction->value;

		return $this;
	}

	private function normalizeDirection(string|SortDirection $direction): SortDirection
	{
		if ($direction instanceof SortDirection)
		{
			return $direction;
		}

		return strtoupper($direction) === SortDirection::Desc->value
			? SortDirection::Desc
			: SortDirection::Asc
		;
	}
}
