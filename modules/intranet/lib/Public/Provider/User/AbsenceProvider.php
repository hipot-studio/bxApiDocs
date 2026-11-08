<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Provider\User;

use Bitrix\Intranet\Internal\Entity\AbsenceType;
use Bitrix\Intranet\Internal\Entity\User\Absence;
use Bitrix\Intranet\Internal\Enum\AbsenceTypeCode;
use Bitrix\Intranet\Internal\Integration\IBlock\AbsenceRepository;
use Bitrix\Intranet\Public\Dto\User\Absence\ActiveVacation;
use Bitrix\Intranet\Public\Dto\User\Absence\ActiveVacationResult;
use Bitrix\Intranet\Public\Dto\User\Absence\BlockingAbsenceBatchResult;
use Bitrix\Intranet\UserAbsence;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

class AbsenceProvider
{
	public const PUSH_SCHEDULE_VERSION = 1;

	private const DAY_END_OFFSET_SECONDS = 86399;
	private const MAX_BATCH_USER_IDS = 300;

	private AbsenceRepository $absenceRepository;
	private int $iblockId;

	public function __construct()
	{
		$this->absenceRepository = ServiceLocator::getInstance()->get('intranet.absence.repository');
		$this->iblockId = UserAbsence::getIblockId();
	}

	public function getTypes(): ?EntityCollection
	{
		if ($this->iblockId <= 0)
		{
			return null;
		}

		return $this->absenceRepository->getAvailableTypes($this->iblockId);
	}

	public function getUserAbsences(int $userId, DateTime $dateFrom, DateTime $dateTo): ?EntityCollection
	{
		if ($this->iblockId <= 0)
		{
			return null;
		}

		return $this->absenceRepository->getCollection($userId, $this->iblockId, $dateFrom, $dateTo);
	}

	/**
	 * @param int[] $userIds
	 */
	public function getUsersBlockingAbsences(
		array $userIds,
		DateTime $dateFrom,
		?DateTime $dateTo = null,
	): BlockingAbsenceBatchResult
	{
		$dateTo ??= $dateFrom;
		$userIds = $this->normalizeUserIds($userIds);
		if (count($userIds) > self::MAX_BATCH_USER_IDS)
		{
			throw new ArgumentOutOfRangeException('userIds', null, self::MAX_BATCH_USER_IDS);
		}
		$hasBlockingAbsenceByUserId = array_fill_keys($userIds, false);
		if ($userIds === [])
		{
			return new BlockingAbsenceBatchResult([], []);
		}
		if ($dateFrom->getTimestamp() > $dateTo->getTimestamp())
		{
			return new BlockingAbsenceBatchResult($hasBlockingAbsenceByUserId, $userIds);
		}

		$queryDateFrom = clone $dateFrom;
		$queryDateFrom->setTime(0, 0, 0);
		try
		{
			$absencesResult = $this->loadUsersAbsences($userIds, $queryDateFrom, $dateTo);
			$types = $this->getTypes();
		}
		catch (\Throwable)
		{
			return new BlockingAbsenceBatchResult($hasBlockingAbsenceByUserId, $userIds);
		}
		if ($types === null)
		{
			return new BlockingAbsenceBatchResult($hasBlockingAbsenceByUserId, $userIds);
		}

		$activeTypeIds = [];
		foreach ($types as $type)
		{
			if ($type instanceof AbsenceType && $type->isActive)
			{
				$activeTypeIds[$type->xmlId] = true;
			}
		}

		foreach ($userIds as $userId)
		{
			if (in_array($userId, $absencesResult['untrustedUserIds'], true))
			{
				continue;
			}

			$absences = $absencesResult['collections'][$userId] ?? null;
			foreach ($absences ?? [] as $absence)
			{
				if (
					$absence instanceof Absence
					&& isset($activeTypeIds[$absence->getTypeXmlId()])
					&& self::intersects($absence, $dateFrom, $dateTo)
				)
				{
					$hasBlockingAbsenceByUserId[$userId] = true;

					break;
				}
			}
		}

		return new BlockingAbsenceBatchResult(
			$hasBlockingAbsenceByUserId,
			$absencesResult['untrustedUserIds'],
		);
	}

	/**
	 * @param int[] $userIds
	 * @return array{collections: array<int, EntityCollection>, untrustedUserIds: int[]}
	 */
	private function loadUsersAbsences(array $userIds, DateTime $dateFrom, DateTime $dateTo): array
	{
		$collections = [];
		foreach ($userIds as $userId)
		{
			$collections[$userId] = new EntityCollection();
		}

		if ($userIds === [])
		{
			return ['collections' => $collections, 'untrustedUserIds' => []];
		}
		if ($this->iblockId <= 0 || $dateFrom->getTimestamp() > $dateTo->getTimestamp())
		{
			return ['collections' => $collections, 'untrustedUserIds' => $userIds];
		}

		try
		{
			$repositoryResult = $this->absenceRepository->getCollectionsByUserIds(
				$userIds,
				$this->iblockId,
				$dateFrom,
				$dateTo,
			);
		}
		catch (\Throwable)
		{
			return ['collections' => $collections, 'untrustedUserIds' => $userIds];
		}

		$untrustedUserIds = $repositoryResult['untrustedUserIds'] ?? [];
		foreach ($userIds as $userId)
		{
			$collection = $repositoryResult['collections'][$userId] ?? null;
			if ($collection instanceof EntityCollection)
			{
				$collections[$userId] = $collection;

				continue;
			}
			$untrustedUserIds[$userId] = true;
		}

		return [
			'collections' => $collections,
			'untrustedUserIds' => array_map('intval', array_keys($untrustedUserIds)),
		];
	}

	public function getActiveVacation(int $userId, DateTime $at): ActiveVacationResult
	{
		$date = $at->format('Y-m-d');
		try
		{
			$absences = $this->getUserAbsences(
				$userId,
				new DateTime($date . ' 00:00:00', 'Y-m-d H:i:s'),
				new DateTime($date . ' 23:59:59', 'Y-m-d H:i:s'),
			);
		}
		catch (\Throwable)
		{
			return ActiveVacationResult::untrusted();
		}
		if ($absences === null)
		{
			return ActiveVacationResult::untrusted();
		}

		foreach ($absences as $absence)
		{
			if (
				$absence instanceof Absence
				&& $absence->getTypeXmlId() === AbsenceTypeCode::Vacation->value
				&& (int)$absence->getId() > 0
				&& self::intersects($absence, $at, $at)
			)
			{
				return ActiveVacationResult::trusted(new ActiveVacation(
					(int)$absence->getId(),
					$absence->getDateTo()->format('Y-m-d'),
				));
			}
		}

		return ActiveVacationResult::trusted(null);
	}

	public function getUserAbsencesByDay(int $userId, ?Date $day = null): ?EntityCollection
	{
		if ($this->iblockId <= 0)
		{
			return null;
		}

		[$dateFrom, $dateTo] = self::getDayBounds($day ?? new Date());

		return $this->sortByDayIntersection(
			$this->absenceRepository->getCollection($userId, $this->iblockId, $dateFrom, $dateTo),
			$dateFrom,
			$dateTo,
		);
	}

	public function getUsersAbsencesByDay(array $userIds, ?Date $day = null): ?array
	{
		$userIds = $this->normalizeUserIds($userIds);
		if (count($userIds) > self::MAX_BATCH_USER_IDS)
		{
			throw new ArgumentOutOfRangeException('userIds', null, self::MAX_BATCH_USER_IDS);
		}
		if ($this->iblockId <= 0)
		{
			return null;
		}
		if ($userIds === [])
		{
			return [];
		}

		[$dateFrom, $dateTo] = self::getDayBounds($day ?? new Date());
		$collections = $this->absenceRepository->getCollectionOfEachUser(
			$userIds,
			$this->iblockId,
			$dateFrom,
			$dateTo,
		);

		$absencesByUserId = [];
		foreach ($userIds as $userId)
		{
			$absencesByUserId[$userId] = $this->sortByDayIntersection(
				$collections[$userId] ?? new EntityCollection(),
				$dateFrom,
				$dateTo,
			);
		}

		return $absencesByUserId;
	}

	public function addUserAbsence(
		int $userId,
		DateTime $dateFrom,
		DateTime $dateTo,
		string $description,
		string $absenceTypeXmlId = AbsenceTypeCode::Vacation->value,
	): Result
	{
		$result = new Result();
		if ($this->iblockId <= 0)
		{
			return $result->addError(new Error('Invalid Iblock ID'));
		}

		$absence = new Absence(
			$userId,
			$dateFrom,
			$dateTo,
			$description,
			$absenceTypeXmlId,
			$this->absenceRepository::getTypeCaption($absenceTypeXmlId),
		);
		$result = $this->absenceRepository->set($this->iblockId, $absence);

		UserAbsence::cleanCache();

		return $result;
	}

	private function sortByDayIntersection(
		EntityCollection $collection,
		DateTime $dateFrom,
		DateTime $dateTo,
	): EntityCollection
	{
		$absences = [];
		foreach ($collection as $absence)
		{
			if (self::getDayIntersectionDuration($absence, $dateFrom, $dateTo) > 0)
			{
				$absences[] = $absence;
			}
		}

		if (count($collection) < 2 && count($absences) === count($collection))
		{
			return $collection;
		}

		usort(
			$absences,
			static function (Absence $left, Absence $right) use ($dateFrom, $dateTo): int
			{
				$durationCompare =
					self::getDayIntersectionDuration($right, $dateFrom, $dateTo)
					<=> self::getDayIntersectionDuration($left, $dateFrom, $dateTo)
				;

				if ($durationCompare !== 0)
				{
					return $durationCompare;
				}

				$startCompare = $right->getDateFrom()->getTimestamp() <=> $left->getDateFrom()->getTimestamp();
				if ($startCompare !== 0)
				{
					return $startCompare;
				}

				return ($left->getId() ?? 0) <=> ($right->getId() ?? 0);
			}
		);

		$sortedCollection = new EntityCollection();
		foreach ($absences as $absence)
		{
			$sortedCollection->add($absence);
		}

		return $sortedCollection;
	}

	private static function getDayBounds(Date $day): array
	{
		$date = $day->format('Y-m-d');

		return [
			new DateTime($date . ' 00:00:00', 'Y-m-d H:i:s'),
			new DateTime($date . ' 23:59:59', 'Y-m-d H:i:s'),
		];
	}

	private static function getDayIntersectionDuration(
		Absence $absence,
		DateTime $dateFrom,
		DateTime $dateTo,
	): int
	{
		$absenceFrom = $absence->getDateFrom()->getTimestamp();
		$absenceTo = $absence->getDateTo()->getTimestamp();

		if (
			$absenceTo >= $absenceFrom
			&& !$absence->dateToContainsTime()
		)
		{
			$absenceTo += self::DAY_END_OFFSET_SECONDS;
		}

		$intersectionStart = max($absenceFrom, $dateFrom->getTimestamp());
		$intersectionEnd = min($absenceTo, $dateTo->getTimestamp());

		return max(0, $intersectionEnd - $intersectionStart);
	}

	private static function intersects(Absence $absence, DateTime $dateFrom, DateTime $dateTo): bool
	{
		$absenceTo = $absence->getDateTo();
		if (!$absence->dateToContainsTime())
		{
			$absenceTo = clone $absenceTo;
			$absenceTo->setTime(0, 0, 0);
			$absenceTo->add('+1 day');
			$absenceTo->add('-1 second');
		}

		return $absence->getDateFrom()->getTimestamp() <= $dateTo->getTimestamp()
			&& $dateFrom->getTimestamp() <= $absenceTo->getTimestamp();
	}

	private function normalizeUserIds(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));
	}
}
