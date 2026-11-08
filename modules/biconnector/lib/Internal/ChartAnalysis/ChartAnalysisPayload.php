<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\ChartAnalysis;

/**
 * The bxChartAnalyze message of the Superset iframe (CONTRACT-superset v4, section 5), read defensively.
 *
 * The card is shown to the user and is bounded here; the data block is meant for the model and is kept
 * exactly as it came - the portal neither reads nor recounts it.
 */
final class ChartAnalysisPayload
{
	public const VERSION = 4;

	/** The whole message is refused before decoding when it is larger than this. */
	public const MAX_JSON_BYTES = 131072;

	public const MAX_FILTERS = 20;

	public const PREVIOUS_STATUSES = ['ok', 'empty', 'failed', 'not_comparable'];

	public const FILTER_SOURCE_CROSS = 'cross';
	public const FILTER_SOURCES = ['native', self::FILTER_SOURCE_CROSS, 'chart'];

	private const MAX_REQUEST_ID_LENGTH = 100;
	private const MAX_VIZ_TYPE_LENGTH = 100;
	private const MAX_SLICE_NAME_LENGTH = 200;
	private const MAX_PERIOD_LABEL_LENGTH = 200;
	private const MAX_FILTER_LABEL_LENGTH = 100;
	private const MAX_FILTER_VALUE_LENGTH = 100;
	private const ELLIPSIS = '…';

	/**
	 * @param list<array{label: string, value: string, source?: string}> $filters
	 */
	private function __construct(
		public readonly string $requestId,
		public readonly int $supersetDashboardId,
		public readonly int $sliceId,
		public readonly string $vizType,
		public readonly string $sliceName,
		public readonly ?string $periodLabel,
		public readonly array $filters,
		public readonly ?string $previousStatus,
		public readonly array $data,
	)
	{
	}

	public static function fromJson(string $json): ?self
	{
		if (strlen($json) > self::MAX_JSON_BYTES)
		{
			return null;
		}

		// Decoded as objects first: an associative array cannot tell the object the contract asks for
		// from a list.
		try
		{
			$shape = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
			$message = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException)
		{
			return null;
		}

		if (
			!$shape instanceof \stdClass
			|| !(($shape->card ?? null) instanceof \stdClass)
			|| !(($shape->data ?? null) instanceof \stdClass)
			|| ($message['version'] ?? null) !== self::VERSION
		)
		{
			return null;
		}

		$requestId = $message['requestId'] ?? null;
		$card = $message['card'];
		$sliceId = $card['sliceId'] ?? null;
		$dashboardId = $card['dashboardId'] ?? null;
		$sliceName = $card['sliceName'] ?? null;

		if (
			!is_string($requestId)
			|| $requestId === ''
			|| mb_strlen($requestId) > self::MAX_REQUEST_ID_LENGTH
			|| !is_int($sliceId)
			|| $sliceId <= 0
			|| !is_int($dashboardId)
			|| $dashboardId <= 0
			|| !is_string($sliceName)
			|| trim($sliceName) === ''
		)
		{
			return null;
		}

		$vizType = $card['vizType'] ?? '';
		$periodLabel = $card['periodLabel'] ?? null;
		$previousStatus = $message['previousStatus'] ?? null;

		return new self(
			requestId: $requestId,
			supersetDashboardId: $dashboardId,
			sliceId: $sliceId,
			vizType: is_string($vizType) ? mb_substr($vizType, 0, self::MAX_VIZ_TYPE_LENGTH) : '',
			sliceName: self::truncate($sliceName, self::MAX_SLICE_NAME_LENGTH),
			periodLabel: is_string($periodLabel) && trim($periodLabel) !== ''
				? self::truncate($periodLabel, self::MAX_PERIOD_LABEL_LENGTH)
				: null
			,
			filters: self::readFilters($card['appliedFilters'] ?? null),
			previousStatus: in_array($previousStatus, self::PREVIOUS_STATUSES, true) ? $previousStatus : null,
			data: $message['data'],
		);
	}

	/**
	 * Cross-filters go first, so the limit never drops them: the tools of the model do not apply them, and the
	 * model has to be told about each one.
	 *
	 * @return list<array{label: string, value: string, source?: string}> An unknown source is left out.
	 */
	private static function readFilters(mixed $appliedFilters): array
	{
		if (!is_array($appliedFilters))
		{
			return [];
		}

		$crossFilters = [];
		$otherFilters = [];
		foreach ($appliedFilters as $filter)
		{
			$label = is_array($filter) ? ($filter['label'] ?? null) : null;
			$value = is_array($filter) ? ($filter['value'] ?? null) : null;
			if (!is_string($label) || !is_string($value) || trim($label) === '')
			{
				continue;
			}

			$item = [
				'label' => self::truncate($label, self::MAX_FILTER_LABEL_LENGTH),
				'value' => self::truncate($value, self::MAX_FILTER_VALUE_LENGTH),
			];

			$source = $filter['source'] ?? null;
			if (in_array($source, self::FILTER_SOURCES, true))
			{
				$item['source'] = $source;
			}

			if ($source === self::FILTER_SOURCE_CROSS)
			{
				$crossFilters[] = $item;
			}
			else
			{
				$otherFilters[] = $item;
			}
		}

		return array_slice(array_merge($crossFilters, $otherFilters), 0, self::MAX_FILTERS);
	}

	private static function truncate(string $value, int $maxLength): string
	{
		if (mb_strlen($value) <= $maxLength)
		{
			return $value;
		}

		return mb_substr($value, 0, $maxLength - 1) . self::ELLIPSIS;
	}
}
