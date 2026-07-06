<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Application;

use Bitrix\Rest\Internal\Repository\SortDirection;

final class AppSort
{
	private const FIELD_ID = 'ID';
	private const FIELD_CODE = 'CODE';
	private const FIELD_NAME = 'APP_NAME';
	private const FIELD_VERSION = 'VERSION';
	private const FIELD_DATE_CREATE = 'DATE_CREATE';
	private const FIELD_DATE_INSTALL = 'DATE_INSTALL';

	/** @var array<string, string> */
	private array $sort = [];

	public function __construct(array $raw = [])
	{
		foreach ($raw as $field => $direction)
		{
			$dir = $this->normalizeDirection($direction);

			match ($field) {
				self::FIELD_ID           => $this->byId($dir),
				self::FIELD_CODE         => $this->byCode($dir),
				self::FIELD_NAME         => $this->byName($dir),
				self::FIELD_VERSION      => $this->byVersion($dir),
				self::FIELD_DATE_CREATE  => $this->byDateCreate($dir),
				self::FIELD_DATE_INSTALL => $this->byDateInstall($dir),
				default                  => null,
			};
		}
	}

	public function byId(SortDirection $direction = SortDirection::Asc): self
	{
		return $this->add(self::FIELD_ID, $direction);
	}

	public function byCode(SortDirection $direction = SortDirection::Asc): self
	{
		return $this->add(self::FIELD_CODE, $direction);
	}

	public function byName(SortDirection $direction = SortDirection::Asc): self
	{
		return $this->add(self::FIELD_NAME, $direction);
	}

	public function byVersion(SortDirection $direction = SortDirection::Asc): self
	{
		return $this->add(self::FIELD_VERSION, $direction);
	}

	public function byDateCreate(SortDirection $direction = SortDirection::Asc): self
	{
		return $this->add(self::FIELD_DATE_CREATE, $direction);
	}

	public function byDateInstall(SortDirection $direction = SortDirection::Asc): self
	{
		return $this->add(self::FIELD_DATE_INSTALL, $direction);
	}

	public function isEmpty(): bool
	{
		return empty($this->sort);
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
