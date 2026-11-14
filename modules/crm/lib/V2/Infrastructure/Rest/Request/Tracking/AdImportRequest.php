<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking;

use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Period;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;

final class AdImportRequest extends Request
{
	public ?int $sourceId = null;

	public ?string $dateFrom = null;

	public ?string $dateTo = null;

	public function getSourceId(): int
	{
		if ($this->sourceId === null || $this->sourceId < 1 || $this->sourceId > 2147483647)
		{
			throw $this->invalid('sourceId');
		}

		return $this->sourceId;
	}

	public function getPeriod(): Period
	{
		if ($this->dateFrom === null || $this->dateTo === null)
		{
			throw $this->invalid($this->dateFrom === null ? 'dateFrom' : 'dateTo');
		}
		$timezone = Option::get('main', 'default_time_zone', '') ?: date_default_timezone_get();
		$zone = new \DateTimeZone($timezone);

		return Period::fromStrings($this->dateFrom, $this->dateTo, $zone, new \DateTimeImmutable('now', $zone));
	}

	private function invalid(string $field): RequestValidationException
	{
		return new RequestValidationException([new Error('Invalid advertising import request.', $field)]);
	}
}
