<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest;

use Bitrix\Im\V2\Common\PeriodAgentTrait;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

/**
 * ACTIVE='N' instead of DELETE: the relation/recent SQL queries and
 * \CUser::Authorize already filter by ACTIVE, and skipping OnUserDelete
 * (60+ handlers) lets one tick process a larger batch.
 */
class CleanupService
{
	use PeriodAgentTrait;

	protected const INACTIVITY_INTERVAL = '-1 day';
	public const GUEST_LIMIT = 5;

	protected const AGENT_SHORT_PERIOD = 60;
	protected const AGENT_LONG_PERIOD = 3600;

	protected DateTime $inactivityThreshold;

	public function __construct()
	{
		$this->inactivityThreshold = (new DateTime())->add(self::INACTIVITY_INTERVAL);
	}

	public static function cleanInactiveGuestsAgent(): string
	{
		$service = new self();
		$hasMore = $service->cleanInactiveGuests();
		$service->calculateAndSetPeriod(true, $hasMore);

		return self::getAgentName();
	}

	/**
	 * @return bool true when the batch came back full — there might be more pending guests.
	 */
	public function cleanInactiveGuests(): bool
	{
		$guestIds = $this->fetchInactiveGuestIds();

		$cUser = new \CUser();
		foreach ($guestIds as $guestId)
		{
			$cUser->Update($guestId, ['ACTIVE' => 'N']);
		}

		return count($guestIds) >= self::GUEST_LIMIT;
	}

	/**
	 * @return int[]
	 */
	protected function fetchInactiveGuestIds(): array
	{
		$rows = $this->buildInactiveGuestsQuery()
			->setOrder(['ID' => 'ASC'])
			->setLimit(self::GUEST_LIMIT)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * Hits ix_b_user_external_auth_id_active + ix_b_user_activity_date.
	 * LAST_ACTIVITY_DATE may be NULL for guests that never logged in — DATE_REGISTER is the fallback.
	 */
	protected function buildInactiveGuestsQuery(): Query
	{
		$inactivityCondition = (new ConditionTree())
			->logic(ConditionTree::LOGIC_OR)
			->where('LAST_ACTIVITY_DATE', '<', $this->inactivityThreshold)
			->where(
				(new ConditionTree())
					->whereNull('LAST_ACTIVITY_DATE')
					->where('DATE_REGISTER', '<', $this->inactivityThreshold)
			)
		;

		return UserTable::query()
			->setSelect(['ID'])
			->where('EXTERNAL_AUTH_ID', UserGuest::AUTH_ID)
			->where('ACTIVE', 'Y')
			->where($inactivityCondition)
		;
	}

	protected static function getAgentName(): string
	{
		return '\\' . self::class . '::cleanInactiveGuestsAgent();';
	}

	protected function calculateAndSetPeriod(bool $fromAgent, bool $hasMore): void
	{
		$period = $hasMore ? self::AGENT_SHORT_PERIOD : self::AGENT_LONG_PERIOD;
		self::setPeriodByName($fromAgent, self::getAgentName(), static fn(): int => $period);
	}

	protected static function isAgentPeriodShort(int $newPeriod): bool
	{
		return $newPeriod === self::AGENT_SHORT_PERIOD;
	}
}
