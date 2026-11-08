<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\Activity\Mixins;

use Bitrix\Main\Type\DateTime;

/**
 * The initiator and the moment of the event a crm trigger publishes: from the event in a live run and from the
 * user who started a test run ({@see \Bitrix\Bizproc\Activity\BaseTrigger::resolveTestRunReturnValues()}).
 * The host declares EVENT_INITIATOR_ID and EVENT_DATE_TIME_ID.
 */
trait EventOutputsTrait
{
	use EventInitiatorTrait;

	public static function getTestRunResolvedReturnNames(): array
	{
		return [self::EVENT_INITIATOR_ID, self::EVENT_DATE_TIME_ID];
	}

	public static function resolveTestRunReturnValues(array $complexDocumentId, int $userId): array
	{
		return static::buildEventOutputs($userId);
	}

	protected static function buildEventOutputs(?int $initiatorUserId): array
	{
		return [
			self::EVENT_INITIATOR_ID => $initiatorUserId ? 'user_' . $initiatorUserId : null,
			self::EVENT_DATE_TIME_ID => (new DateTime())->format(DateTime::getFormat()),
		];
	}
}
