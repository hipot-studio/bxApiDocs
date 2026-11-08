<?php

namespace Bitrix\StaffTrack\Public\Command\CheckIn;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Text\Emoji;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Helper\DateHelper;
use Bitrix\StaffTrack\Public\Command\CheckInDto;
use Bitrix\StaffTrack\Internal\Repository\Mapper\CheckInMapper;
use Bitrix\StaffTrack\Internal\Integration\Disk\FileResolver;
use Bitrix\StaffTrack\Internal\Integration\Pull;
use Bitrix\StaffTrack\Public\Provider\DepartmentProvider;
use Bitrix\StaffTrack\Service\UserService;
abstract class AbstractCommand
{
	public const ERROR_GEO_ENCRYPTION_UNAVAILABLE = 'STAFFTRACK_CHECK_IN_GEO_ENCRYPTION_UNAVAILABLE';

	protected CheckInMapper $mapper;
	protected CheckInDto $checkInDto;

	/** @var array<int, int[]> */
	private array $headIdsByUser = [];

	public function __construct()
	{
		$this->init();
	}

	abstract public function execute(CheckInDto $checkInDto): Result;

	protected function init(): void
	{
		$this->mapper = new CheckInMapper();
	}

	/**
	 * @param CheckInDto[] $checkInDtos
	 */
	protected static function refuseIfGeoEncryptionUnavailable(array $checkInDtos): ?Error
	{
		if (Feature::isGeoEncryptionAvailable())
		{
			return null;
		}

		foreach ($checkInDtos as $checkInDto)
		{
			if (self::carriesGeo($checkInDto))
			{
				return new Error(
					'Geo data of a check-in cannot be encrypted on this portal',
					self::ERROR_GEO_ENCRYPTION_UNAVAILABLE,
				);
			}
		}

		return null;
	}

	private static function carriesGeo(CheckInDto $checkInDto): bool
	{
		return $checkInDto->latitude !== 0.0
			|| $checkInDto->longitude !== 0.0
			|| ($checkInDto->address ?? '') !== ''
		;
	}

	public function sendPushToDepartment(Pull\PushCommand $command, array $extraPayload = []): void
	{
		$departmentIds = DepartmentProvider::getDepartmentIdsByUserId($this->checkInDto->userId);
		$checkIn = $this->checkInDto->toArray();
		$checkIn['description'] = Emoji::decode($checkIn['description'] ?? '');

		if (!empty($this->checkInDto->fileIds))
		{
			$checkIn['files'] = (new FileResolver())->resolve(
				$this->checkInDto->id,
				$this->checkInDto->fileIds,
				$this->checkInDto->userId,
			);
		}

		$payload = array_merge(
			[
				'checkIn' => $checkIn,
				'user' => UserService::getInstance()->getUser($this->checkInDto->userId),
				'departmentIds' => $departmentIds,
			],
			$extraPayload,
		);

		$this->pushByTag($this->checkInDto->userId, $command, $payload);

		foreach ($this->getHeadIds($this->checkInDto->userId) as $headId)
		{
			$this->pushByTag($headId, $command, $payload);
		}
	}

	/**
	 * @return int[]
	 */
	protected function getHeadIds(int $userId): array
	{
		return $this->headIdsByUser[$userId] ??= array_values(
			array_filter(
				$this->loadHeadIds($userId),
				static fn (int $headId): bool => DepartmentProvider::canReadCheckInsOf($headId, $userId),
			),
		);
	}

	/**
	 * @return int[]
	 */
	protected function loadHeadIds(int $userId): array
	{
		if (Feature::isCheckInDeputyAccessEnabled())
		{
			return DepartmentProvider::getManagerIdsForEmployees([$userId]);
		}

		return DepartmentProvider::getDepartmentHeadsIdsByUserId($userId);
	}

	/**
	 * The one place a check-in push leaves the command through, so that who receives it is decided in a
	 * single spot rather than at every call site.
	 */
	protected function pushByTag(int $recipientId, Pull\PushCommand $command, array $payload): void
	{
		Pull\PushSender::sendByTag(
			Pull\Tag::getUserTag($recipientId),
			$command,
			$payload,
		);
	}

	/**
	 * The push goes to the author and to their heads, and the delta is parsed by the client of each
	 * recipient, whose version follows that recipient's own rollout flag. So the delta is needed as
	 * soon as the rollout is on for at least one of them, not only for the author.
	 *
	 * Resolving the flags can walk the department tree, so callers run this before their transaction.
	 */
	protected function isDeltaNeededByRecipients(int $userId): bool
	{
		return $this->isRolloutEnabledForAny(array_merge([$userId], $this->getHeadIds($userId)));
	}

	/**
	 * The batch delta is parsed by every recipient, the author included: their own map reads the
	 * check-ins of the push rather than its delta, but the timeline sheet they may have open keys on the
	 * lastMarker only the delta carries. So the rule is the one of a single add.
	 */
	protected function isBatchDeltaNeededByRecipients(int $userId): bool
	{
		return $this->isDeltaNeededByRecipients($userId);
	}

	/**
	 * @param int[] $recipientIds
	 */
	private function isRolloutEnabledForAny(array $recipientIds): bool
	{
		if (empty($recipientIds))
		{
			return false;
		}

		return in_array(true, Feature::filterCheckInRolloutEnabled($recipientIds), true);
	}

	/**
	 * Sends a single aggregated push for a batch of a user's check-in points.
	 * Department/head lookup is performed once per batch.
	 *
	 * One push per local day the batch touched, and not one push per batch. A delta speaks for a single
	 * local day - the day its counter and its last marker were read off - and a receiver applies it only
	 * to the day it has on screen. A batch drained after an offline night carries both sides of midnight,
	 * so the earlier day needs a push of its own or the screen showing it is left with a stale tail until
	 * somebody reopens it.
	 *
	 * The form of a push does not change: every one of them is the same single-day message every released
	 * client already reads. The lookups of the author, the department and the heads are made once for the
	 * batch, whatever number of days it turns into.
	 *
	 * @param CheckInDto[] $checkInDtos
	 * @param array<int, array> $deltas the stats deltas, one per local day, same role as in
	 *        {@see sendPushToDepartment()}. An empty list sends the batch as one push carrying no delta.
	 */
	public function sendBatchPushToDepartment(array $checkInDtos, array $deltas = []): void
	{
		if (empty($checkInDtos))
		{
			return;
		}

		$userId = $checkInDtos[0]->userId;
		$departmentIds = DepartmentProvider::getDepartmentIdsByUserId($userId);
		$user = UserService::getInstance()->getUser($userId);
		$recipientIds = array_merge([$userId], $this->getHeadIds($userId));

		$deltaByDate = [];
		foreach ($deltas as $delta)
		{
			$localDate = $delta['localDate'] ?? null;
			if (is_string($localDate) && $localDate !== '')
			{
				$deltaByDate[$localDate] = $delta;
			}
		}

		foreach ($this->groupBatchPushCheckInsByLocalDate($checkInDtos, empty($deltaByDate)) as $localDate => $checkIns)
		{
			$payload = array_merge(
				[
					'checkIns' => $checkIns,
					'user' => $user,
					'departmentIds' => $departmentIds,
				],
				$deltaByDate[$localDate] ?? [],
			);

			foreach ($recipientIds as $recipientId)
			{
				$this->pushByTag($recipientId, Pull\PushCommand::CHECK_IN_BATCH_ADD, $payload);
			}
		}
	}

	/**
	 * The points of the batch under the local day each of them belongs to, in date order. Grouping is by
	 * the day of the point and not by the days the deltas name, so a point reaches its readers exactly
	 * once even if no delta was built for its day at all.
	 *
	 * @param CheckInDto[] $checkInDtos
	 * @param bool $asOneGroup keeps the whole batch in a single push, for a caller that has no delta to
	 *        split by: without one there is nothing a second push would carry that the first does not
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function groupBatchPushCheckInsByLocalDate(array $checkInDtos, bool $asOneGroup): array
	{
		$groups = [];

		foreach ($checkInDtos as $checkInDto)
		{
			$timestamp = $checkInDto->dateCreate?->getTimestamp() ?? 0;
			$localDate = $asOneGroup
				? ''
				: date('Y-m-d', $timestamp + (int)($checkInDto->userTimezone ?? 0));

			$groups[$localDate][] = $this->buildBatchPushCheckIn($checkInDto);
		}

		ksort($groups);

		return $groups;
	}

	/**
	 * One point of a batch push, listed field by field rather than taken off the DTO as a whole: the DTO
	 * carries manual-form fields nobody reads, and the push goes to every head of its author. Keep
	 * `userTimezone` - the recipient counts the local day of the point by it.
	 *
	 * @return array<string, mixed>
	 */
	protected function buildBatchPushCheckIn(CheckInDto $checkInDto): array
	{
		$dateCreate = $checkInDto->dateCreate;

		return [
			'id' => $checkInDto->id,
			'userId' => $checkInDto->userId,
			'entityType' => $checkInDto->entityType,
			'latitude' => $checkInDto->latitude,
			'longitude' => $checkInDto->longitude,
			// the same UTC form AbstractDto::toArray() used to hand the client
			'dateCreate' => $dateCreate === null
				? ''
				: DateHelper::getInstance()->getDateUtc($dateCreate)->format(DateHelper::CLIENT_DATETIME_FORMAT),
			'description' => Emoji::decode($checkInDto->description ?? ''),
			'address' => $checkInDto->address,
			'userTimezone' => $checkInDto->userTimezone,
		];
	}
}
