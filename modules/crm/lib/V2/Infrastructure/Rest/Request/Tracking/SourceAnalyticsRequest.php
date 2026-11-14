<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking;

use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Period;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Refusal;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\OffsetPaginatedListRequest;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\PaginationStructure;

final class SourceAnalyticsRequest extends Request implements OffsetPaginatedListRequest
{
	public array $sourceIds;

	public string $dateFrom;

	public string $dateTo;

	public ?PaginationStructure $pagination = null;

	/** @return list<int> */
	public function getSourceIds(): array
	{
		if (!array_is_list($this->sourceIds) || $this->sourceIds === [])
		{
			throw $this->invalid('sourceIds');
		}

		$unique = [];
		foreach ($this->sourceIds as $sourceId)
		{
			if (!is_int($sourceId) || $sourceId < 1 || $sourceId > 2147483647)
			{
				throw $this->invalid('sourceIds');
			}
			$unique[$sourceId] = $sourceId;
		}
		if (count($unique) > 100)
		{
			throw $this->invalid('sourceIds');
		}

		return array_values($unique);
	}

	public function getPeriod(): Period
	{
		$timezone = Option::get('main', 'default_time_zone', '') ?: date_default_timezone_get();
		$zone = new \DateTimeZone($timezone);

		try
		{
			return Period::fromStrings($this->dateFrom, $this->dateTo, $zone, new \DateTimeImmutable('now', $zone));
		}
		catch (\DomainException $exception)
		{
			if ($exception->getMessage() !== Refusal::PeriodInvalid->value)
			{
				throw $exception;
			}

			throw new RequestValidationException([
				new Error('Invalid source analytics period.', 'dateFrom'),
				new Error('Invalid source analytics period.', 'dateTo'),
			]);
		}
	}

	/** @return array{limit: int, offset: int} */
	public function getPagination(): array
	{
		$pagination = $this->pagination ?? PaginationStructure::create([]);
		return ['limit' => $pagination->getLimit(), 'offset' => $pagination->getOffset()];
	}

	private function invalid(string $field): RequestValidationException
	{
		return new RequestValidationException([new Error('Invalid source analytics request.', $field)]);
	}
}
