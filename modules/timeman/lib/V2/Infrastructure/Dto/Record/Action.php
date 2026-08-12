<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Dto\Record;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Timeman\Model\Schedule\ScheduleTable;

final class Action
{
	public function __construct(
		#[InArray([
			ScheduleTable::ALLOWED_DEVICES_BROWSER,
			ScheduleTable::ALLOWED_DEVICES_MOBILE,
			ScheduleTable::ALLOWED_DEVICES_B24TIME,
			null,
		], strict: true, showValues: true)]
		public readonly ?string $device = null,
		public readonly ?string $reason = null,
		public readonly ?int $stopTimestamp = null,
	)
	{
	}

	public static function createFromRequest(HttpRequest $request): self
	{
		$requestData = array_merge(
			$request->toArray(),
			$request->getJsonList()->toArray(),
		);

		$device = trim((string)($requestData['device'] ?? ''));
		$reason = trim((string)($requestData['reason'] ?? ''));
		$stopTimestamp = $requestData['stopTimestamp'] ?? null;
		$stopTimestamp = is_numeric($stopTimestamp) ? (int)$stopTimestamp : null;

		return new self(
			device: ($device !== '') ? $device : null,
			reason: ($reason !== '') ? $reason : null,
			stopTimestamp: $stopTimestamp,
		);
	}
}
