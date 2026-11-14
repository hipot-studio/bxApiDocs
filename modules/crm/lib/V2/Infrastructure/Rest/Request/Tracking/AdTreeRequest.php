<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking;

use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Period;
use Bitrix\Main\Error;
use Bitrix\Main\Config\Option;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

final class AdTreeRequest extends Request
{
	public int $sourceId;

	public string $dateFrom;

	public string $dateTo;

	public string $level = 'campaign';

	public ?int $parentId = null;

	public ?AdTreeCursor $cursor = null;

	public ?SelectStructure $select = null;

	public function getSourceId(): int
	{
		if ($this->sourceId < 1 || $this->sourceId > 2147483647)
		{
			throw $this->invalid('sourceId');
		}

		return $this->sourceId;
	}

	public function getPeriod(): Period
	{
		$timezone = Option::get('main', 'default_time_zone', '') ?: date_default_timezone_get();
		$zone = new \DateTimeZone($timezone);

		return Period::fromStrings($this->dateFrom, $this->dateTo, $zone, new \DateTimeImmutable('now', $zone));
	}

	public function getLevel(): string
	{
		if (!in_array($this->level, ['campaign', 'group', 'keyword'], true))
		{
			throw $this->invalid('level');
		}

		return $this->level;
	}

	public function getParentId(): ?int
	{
		if ($this->parentId === null)
		{
			return null;
		}
		if ($this->parentId < 1 || $this->parentId > 2147483647)
		{
			throw $this->invalid('parentId');
		}

		return $this->parentId;
	}

	/** @return array{limit: int, cursor?: string} */
	public function getPagination(): array
	{
		if ($this->cursor === null)
		{
			return ['limit' => 50];
		}
		if ($this->cursor->getField() !== 'id' || $this->cursor->getLimit() < 1
			|| $this->cursor->getLimit() > 100)
		{
			throw $this->invalid('cursor');
		}
		$value = $this->cursor->getValue();
		if (!is_string($value) || $value === '' || strlen($value) > 4096)
		{
			throw $this->invalid('cursor.value');
		}

		return ['limit' => $this->cursor->getLimit(), 'cursor' => $value];
	}

	private function invalid(string $field): RequestValidationException
	{
		return new RequestValidationException([new Error('Invalid advertising tree request.', $field)]);
	}
}
