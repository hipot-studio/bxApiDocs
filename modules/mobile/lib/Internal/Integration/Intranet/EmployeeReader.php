<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Intranet;

use Bitrix\Intranet;
use Bitrix\Intranet\ActionFilter\UserType;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

/**
 * Integration layer for the users of the intranet domain, read for a whole pack of users at once.
 * All other classes of the shared facts must use this class instead of querying the intranet user
 * table directly.
 *
 * "A colleague" is an active real employee: USER_TYPE keeps extranet guests, collab participants
 * and integrator accounts out, REAL_USER keeps bot, imconnector, email and sale accounts out.
 * "A colleague who joined" is one who also finished registration - the confirm code is cleared on
 * registration, so an empty one is what tells "joined" from "merely invited". It is the one
 * definition the gates of the pushes run on.
 *
 * Every answer is null when the intranet module is unavailable: an unknown, not an empty answer. A
 * read that fails throws what it met, since a failure is not an unknown.
 */
class EmployeeReader
{
	/**
	 * How many users one query covers: the list of an IN has to stay a statement of a bounded size.
	 */
	public const CHUNK_SIZE = 500;

	/**
	 * The intranet type of each user the portal has; a user it does not have is absent.
	 *
	 * @param int[] $userIds
	 * @return array<int, string>|null userId => type, such as "employee", "extranet" or "bot"
	 */
	public function getUserTypes(array $userIds): ?array
	{
		return $this->readByPortions(
			$userIds,
			static function (array $chunk): array {
				$rows = Intranet\UserTable::query()
					->setSelect(['ID', 'USER_TYPE'])
					->whereIn('ID', $chunk)
					->fetchAll()
				;

				return array_column($rows, 'USER_TYPE', 'ID');
			},
		);
	}

	/**
	 * The users a campaign may select at all: active, registered, in a department and not an external
	 * account.
	 *
	 * @param int[] $userIds
	 * @return int[]|null
	 */
	public function getWorkingEmployees(array $userIds): ?array
	{
		$working = $this->readByPortions(
			$userIds,
			static function (array $chunk): array {
				$rows = UserTable::getList([
					'select' => ['ID'],
					'filter' => [
						'=ID' => $chunk,
						'=ACTIVE' => 'Y',
						'=CONFIRM_CODE' => false,
						'!=UF_DEPARTMENT' => false,
						'!=EXTERNAL_AUTH_ID' => UserTable::getExternalUserTypes(),
					],
				])->fetchAll();

				return array_fill_keys(array_column($rows, 'ID'), true);
			},
		);

		return $working === null ? null : array_keys($working);
	}

	/**
	 * The users who have a colleague that joined, at or after the user's own threshold when one is
	 * given.
	 *
	 * One query answers any pack: two colleagues decide for everyone, since one of them is somebody
	 * else than the user. When a user has a threshold of his own, later than the one the query holds,
	 * they are the two who joined last, so the later of them is compared against it.
	 *
	 * @param int[] $userIds
	 * @param array<int, DateTime> $joinedSince userId => earliest registration that counts
	 * @return int[]|null
	 */
	public function getJoinedColleagueOwners(array $userIds, array $joinedSince = []): ?array
	{
		$userIds = self::normalizeIds($userIds);
		if (!$this->isModuleAvailable())
		{
			return null;
		}

		if ($userIds === [])
		{
			return [];
		}

		$threshold = self::commonThreshold($userIds, $joinedSince);
		$latest = $this->fetchJoinedColleagues(
			$threshold,
			self::hasThresholdOfItsOwn($userIds, $joinedSince, $threshold),
		);

		$owners = [];
		foreach ($userIds as $userId)
		{
			$since = isset($joinedSince[$userId]) ? $joinedSince[$userId]->getTimestamp() : null;
			foreach ($latest as $colleague)
			{
				$isOther = (int)$colleague['ID'] !== $userId;
				if ($isOther && ($since === null || $colleague['DATE_REGISTER']->getTimestamp() >= $since))
				{
					$owners[] = $userId;

					break;
				}
			}
		}

		return $owners;
	}

	/**
	 * The users one of whose invitations by address was accepted: the invitee joined, and the
	 * invitation was sent at or after the threshold. An invitation by link leaves no invitation of the
	 * user, so a colleague who came through one does not count.
	 *
	 * @param int[] $userIds
	 * @param array<int, DateTime> $sincePerUser userId => a threshold replacing the common one
	 * @return int[]|null
	 */
	public function getAcceptedInviters(array $userIds, DateTime $since, array $sincePerUser = []): ?array
	{
		$inviters = $this->readByPortions(
			$userIds,
			function (array $chunk) use ($since, $sincePerUser): array {
				$thresholds = [];
				foreach ($chunk as $userId)
				{
					$thresholds[$userId] = ($sincePerUser[$userId] ?? $since)->getTimestamp();
				}

				$rows = $this->createAcceptedInvitationQuery($chunk, min($thresholds))->fetchAll();

				$accepted = [];
				foreach ($rows as $row)
				{
					$inviterId = (int)$row['ORIGINATOR_ID'];
					if ($row['LAST_INVITED_AT']->getTimestamp() >= $thresholds[$inviterId])
					{
						$accepted[$inviterId] = true;
					}
				}

				return $accepted;
			},
		);

		return $inviters === null ? null : array_keys($inviters);
	}

	/**
	 * The colleagues of the portal as a query of their ids, for a reader of another module that asks
	 * about colleagues inside its own query: whether a colleague finished registration is not asked.
	 */
	public function createColleagueQuery(): Query
	{
		return Intranet\UserTable::query()
			->setSelect(['ID'])
			->where('ACTIVE', 'Y')
			->where('REAL_USER', 'expr', true)
			->where('USER_TYPE', UserType::TYPE_EMPLOYEE)
		;
	}

	protected function isModuleAvailable(): bool
	{
		return Loader::includeModule('intranet');
	}

	/**
	 * Two colleagues who joined at or after the threshold: the two who joined last when $isLatest, since a
	 * threshold of a user's own is then compared against them, any two otherwise.
	 *
	 * @return array<int, array{ID: int|string, DATE_REGISTER: DateTime}>
	 */
	private function fetchJoinedColleagues(?DateTime $threshold, bool $isLatest): array
	{
		$query = $this->createJoinedColleagueQuery()
			->setSelect(['ID', 'DATE_REGISTER'])
			->setLimit(2)
		;
		if ($isLatest)
		{
			$query->setOrder(['DATE_REGISTER' => 'DESC', 'ID' => 'DESC']);
		}
		if ($threshold !== null)
		{
			$query->where('DATE_REGISTER', '>=', $threshold);
		}

		return $query->fetchAll();
	}

	/**
	 * The latest accepted invitation of each inviter of the chunk from the earliest threshold on: one
	 * condition on the date whatever the thresholds, each held against its own inviter after the read.
	 *
	 * @param int[] $userIds
	 * @param int $earliest timestamp of the earliest threshold of the chunk
	 */
	private function createAcceptedInvitationQuery(array $userIds, int $earliest): Query
	{
		return $this->createJoinedColleagueQuery()
			->registerRuntimeField(new ExpressionField(
				'LAST_INVITED_AT',
				'MAX(%s)',
				['INVITATION.DATE_CREATE'],
				['data_type' => 'datetime'],
			))
			->setSelect(['ORIGINATOR_ID' => 'INVITATION.ORIGINATOR_ID', 'LAST_INVITED_AT'])
			->whereColumn('ID', '!=', 'INVITATION.ORIGINATOR_ID')
			->whereIn('INVITATION.ORIGINATOR_ID', $userIds)
			->where('INVITATION.DATE_CREATE', '>=', DateTime::createFromTimestamp($earliest))
			->setGroup(['INVITATION.ORIGINATOR_ID'])
		;
	}

	private function createJoinedColleagueQuery(): Query
	{
		return $this->createColleagueQuery()->addFilter('CONFIRM_CODE', false);
	}

	/**
	 * The earliest threshold when every user has one, so the query can skip the colleagues nobody asks
	 * about; none when some user asks about any colleague.
	 *
	 * @param int[] $userIds
	 * @param array<int, DateTime> $joinedSince
	 */
	private static function commonThreshold(array $userIds, array $joinedSince): ?DateTime
	{
		$threshold = null;
		foreach ($userIds as $userId)
		{
			$since = $joinedSince[$userId] ?? null;
			if ($since === null)
			{
				return null;
			}

			if ($threshold === null || $since->getTimestamp() < $threshold->getTimestamp())
			{
				$threshold = $since;
			}
		}

		return $threshold;
	}

	/**
	 * Whether some user has a threshold later than the one the query holds.
	 *
	 * @param int[] $userIds
	 * @param array<int, DateTime> $joinedSince
	 */
	private static function hasThresholdOfItsOwn(array $userIds, array $joinedSince, ?DateTime $threshold): bool
	{
		foreach ($userIds as $userId)
		{
			$since = $joinedSince[$userId] ?? null;
			if ($since !== null && ($threshold === null || $since->getTimestamp() > $threshold->getTimestamp()))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param int[] $userIds
	 * @param \Closure(int[]): array<int, mixed> $readPortion
	 * @return array<int, mixed>|null
	 */
	private function readByPortions(array $userIds, \Closure $readPortion): ?array
	{
		if (!$this->isModuleAvailable())
		{
			return null;
		}

		$answers = [];
		foreach (array_chunk(self::normalizeIds($userIds), self::CHUNK_SIZE) as $chunk)
		{
			$answers += $readPortion($chunk);
		}

		$normalized = [];
		foreach ($answers as $userId => $answer)
		{
			$normalized[(int)$userId] = $answer;
		}

		return $normalized;
	}

	/**
	 * @param array<int|string> $userIds
	 * @return int[]
	 */
	private static function normalizeIds(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn(int $userId): bool => $userId > 0,
		)));
	}
}
