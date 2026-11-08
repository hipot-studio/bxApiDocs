<?php

namespace Bitrix\BIConnector\Internal\Services\Builder;

use Bitrix\Main\Web\Json;

/**
 * Journal of the refusals of BuilderRestLimiter: the share of refusals per quota class and dimension
 * is what the quotas are revised by. A failure of the journal never changes the answer of the limiter.
 */
class BuilderRestLimiterJournal
{
	public const AUDIT_TYPE_ID = 'BICONNECTOR_BUILDER_REST_LIMIT';

	/**
	 * @param string $quotaClass read or write
	 * @param string|null $dimension portal, user or caller; null when the quota could not be counted
	 */
	public function refused(string $quotaClass, ?string $dimension, int $retryAfter): void
	{
		try
		{
			$this->write([
				'quotaClass' => $quotaClass,
				'limiterDimension' => $dimension,
				'retryAfter' => $retryAfter,
			]);
		}
		catch (\Throwable)
		{
		}
	}

	protected function write(array $event): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_INFO,
			'AUDIT_TYPE_ID' => self::AUDIT_TYPE_ID,
			'MODULE_ID' => 'biconnector',
			'ITEM_ID' => 'builder',
			'DESCRIPTION' => Json::encode($event),
		]);
	}
}
