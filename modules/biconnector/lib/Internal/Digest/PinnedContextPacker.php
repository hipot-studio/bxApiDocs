<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest;

use Bitrix\BIConnector\Internal\Digest\Dto\PinnedContextDto;
use Bitrix\Main\Web\Json;

/**
 * The wire format of the pinned context: the one place the key names of the
 * schema live. The activity that fills the template property packs through it
 * and the tool that applies the pinning unpacks against the same constants, so
 * the two sides of the format cannot drift apart silently.
 *
 * The value is a single-line JSON object. `urlParams` is always present, empty
 * object included; `period` is present on every run except "as in the report"
 * of a report proven to bound its own period, because an absent key and a `null`
 * mean different things to the reader.
 */
final class PinnedContextPacker
{
	public const KEY_DASHBOARD_ID = 'dashboardId';
	public const KEY_URL_PARAMS = 'urlParams';
	public const KEY_PERIOD = 'period';
	public const KEY_RUN_ID = 'runId';
	public const KEY_PERIOD_FROM = 'from';
	public const KEY_PERIOD_TO = 'to';

	public static function pack(PinnedContextDto $context): string
	{
		$payload = [
			self::KEY_DASHBOARD_ID => $context->dashboardId,
			// Cast to keep an empty scope an object of the schema instead of a JSON list.
			self::KEY_URL_PARAMS => (object)$context->scopeValues,
		];

		if ($context->period !== null)
		{
			$payload[self::KEY_PERIOD] = [
				self::KEY_PERIOD_FROM => $context->period[self::KEY_PERIOD_FROM],
				self::KEY_PERIOD_TO => $context->period[self::KEY_PERIOD_TO],
			];
		}

		$payload[self::KEY_RUN_ID] = $context->runId;

		return Json::encode($payload);
	}
}
