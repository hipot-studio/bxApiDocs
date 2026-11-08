<?php

namespace Bitrix\StaffTrack\Internal\Service\CheckIn;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Geo\Geohash;
use Bitrix\StaffTrack\Internal\Geo\GeohashKey;
use Bitrix\StaffTrack\Internal\Integration\Pull;
use Bitrix\StaffTrack\Internal\Repository\AddressQueueRepository;
use Bitrix\StaffTrack\Internal\Repository\AddressRepository;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Public\Provider\GeoProvider;
use Bitrix\StaffTrack\Public\Services\AddressCache;
use Bitrix\StaffTrack\Public\Services\MapDataCache;

/**
 * Shared by both drainers of the address queue - the agent and the request that queued the points: the
 * lock, the pass over one batch and the scheduling of a successor. Both come through
 * AddressResolverService, because this layer is closed to the agent. The agent name constants are the
 * registration of AddressResolveAgent already stored in b_agent, so they may not change.
 */
class AddressResolveQueueWorker
{
	public const QUEUE_LOCK_NAME = 'stafftrack_address_resolve';

	public const RUN_AGENT_NAME = 'Bitrix\\StaffTrack\\Infrastructure\\Agent\\CheckIn\\AddressResolveAgent::run();';
	public const CONTINUATION_AGENT_NAME =
		'Bitrix\\StaffTrack\\Infrastructure\\Agent\\CheckIn\\AddressResolveAgent::runContinuation();'
	;

	private const BATCH_SIZE = 50;
	private const CONTINUATION_DELAY = 5;

	private const MODULE_ID = 'stafftrack';

	/**
	 * The name every record of a pass is written under, taken from the class rather than spelled out. The
	 * pass used to live in the agent and the records kept its name after the move, so a journal entry led
	 * to a file holding none of that code. Derived, a rename of the class carries the records with it; a
	 * literal would have kept the very drift this is here to prevent.
	 */
	private static function logTag(): string
	{
		$name = self::class;

		return substr($name, strrpos($name, '\\') + 1);
	}

	/**
	 * Where the next pass starts when the previous one could do nothing with its batch. Kept in the options
	 * of the module because a pass and its successor are different hits of the portal and share nothing else.
	 */
	private const OPTION_SEEK = 'address_queue_seek';

	/**
	 * How long one pass may spend on the cells of its batch. The batch is bounded by rows, and rows say
	 * nothing about the cost: a batch whose cells are all cached is over in milliseconds, while a batch of
	 * a moving employee, where almost every point names a cell of its own, is fifty calls of the geo service
	 * at about a second each. Both drainers of the queue occupy a worker of the portal for the whole of it -
	 * the agent runs in a hit, the request-time one in a background job after the answer - and both hold
	 * QUEUE_LOCK_NAME, so a pass without a limit parks a worker for the best part of a minute. What the
	 * limit cuts off stays on the queue and is taken by the continuation CONTINUATION_DELAY seconds later.
	 */
	private const PASS_TIME_LIMIT = 20;

	private const VIEWER_DAY_SHIFTS = [-2, -1, 0, 1, 2];

	/**
	 * Registered with a zero interval, so returning its own name would put it back on the very next hit of
	 * the portal while the lock is still held. The queue is handed to the continuation agent instead.
	 */
	public static function runFallback(): string
	{
		if (self::queueStillNeedsAWorker(self::processUnderLock()))
		{
			self::scheduleContinuation();
		}

		return '';
	}

	/**
	 * This one may hand back its own name: it is registered with CONTINUATION_DELAY, so the kernel gives it
	 * a NEXT_EXEC of that many seconds ahead rather than of the hit it is running in.
	 */
	public static function runContinuation(): string
	{
		$hasMore = self::processUnderLock();

		return ($hasMore === true || self::queueStillNeedsAWorker($hasMore)) ? self::CONTINUATION_AGENT_NAME : '';
	}

	/**
	 * Caller MUST hold the QUEUE_LOCK_NAME lock.
	 */
	public static function runOnceLocked(): bool
	{
		try
		{
			if (self::processBatch())
			{
				self::scheduleContinuation();
			}

			return true;
		}
		catch (\Throwable $e)
		{
			AddMessage2Log(self::logTag() . '::runOnceLocked error: ' . $e->getMessage());

			return false;
		}
	}

	/**
	 * A busy lock is not the same answer as a drained queue: an agent of this family is one-shot, so a pass
	 * that leaves without looking at a single row has to put a successor in its place, or the queue is left
	 * with nobody to come back to it.
	 */
	private static function queueStillNeedsAWorker(?bool $hasMore): bool
	{
		return $hasMore === null && AddressQueueRepository::hasRows();
	}

	/**
	 * @return bool|null whether the queue still has work after the batch, null when the lock was busy
	 */
	private static function processUnderLock(): ?bool
	{
		$connection = Application::getConnection();
		if (!$connection->lock(self::QUEUE_LOCK_NAME, 0))
		{
			return null;
		}

		$hasMore = false;

		try
		{
			$hasMore = self::processBatch();

			if ($hasMore)
			{
				self::scheduleContinuation();
			}
		}
		catch (\Throwable $e)
		{
			AddMessage2Log(self::logTag() . ' error: ' . $e->getMessage());
		}
		finally
		{
			$connection->unlock(self::QUEUE_LOCK_NAME);
		}

		return $hasMore;
	}

	/**
	 * Queued points are grouped by map cell, so an unknown cell costs one geo service call - about a
	 * second - for all the points standing in it. That cost is why a batch is bounded and runs behind
	 * the lock.
	 *
	 * A pass takes its rows off the queue whatever came of them, an answer or a failure. The queue holds
	 * no count of tries and nothing ticks over it, so rows kept after a failure would wait for the next
	 * time somebody opens that day in a timeline - and pile up until then. The point loses its address,
	 * not its place on the map.
	 *
	 * The cells of a batch are worked through under PASS_TIME_LIMIT; the ones the limit cuts off keep their
	 * rows and are answered for by the continuation, which the pass asks for by returning true.
	 */
	private static function processBatch(): bool
	{
		if (!Feature::isGeoEncryptionAvailable())
		{
			if (AddressQueueRepository::hasRows())
			{
				AddMessage2Log(self::logTag() . ': geo encryption unavailable, the queue is left as it stands');
			}

			return false;
		}

		$startTime = time();
		$rows = self::takeBatch();
		if (empty($rows))
		{
			return false;
		}

		$repository = new CheckInRepository();
		[$groups, $orphanRows, $deferredRows, $stoppedBy] =
			self::groupByCell($rows, $startTime + self::PASS_TIME_LIMIT);

		if (!empty($deferredRows))
		{
			// Kept on the queue, not dropped: the lookup stopped before it reached the folded days of these
			// points, so "no coordinates" would be a verdict it never made. The reason is named because the
			// two of them are addressed to different people - see reasonOfStopping().
			AddMessage2Log(
				self::logTag() . ': coordinates of ' . count($deferredRows)
				. ' queued points were not looked up, left queued - ' . self::reasonOfStopping($stoppedBy),
			);
		}

		if (!empty($orphanRows))
		{
			// the point kept no place to ask about: neither its raw row nor the aggregated day of its
			// author holds coordinates a service would take. Nothing of that place goes to the journal -
			// the journal is not encrypted
			AddMessage2Log(
				self::logTag() . ': no usable coordinates for ' . count($orphanRows) . ' queued points',
			);
			AddressQueueRepository::deleteRows($orphanRows);
		}

		$pointDatesByAuthor = [];
		$cellsDone = 0;
		$rowsMoved = count($orphanRows);

		try
		{
			foreach ($groups as $group)
			{
				if (self::isTimeExceeded($startTime))
				{
					break;
				}

				try
				{
					$key = GeohashKey::hash($group['geohash']);
					$address = self::resolveByCoords($key, $group['latitude'], $group['longitude']);

					if ($address !== null)
					{
						$userCheckIns = self::applyAddressToGroup($repository, $group['rows'], $address);
						foreach ($group['rows'] as $row)
						{
							$pointDatesByAuthor[(int)$row['USER_ID']][(int)$row['POINT_DATE']] = true;
						}
						self::sendPushToUsers($userCheckIns);
					}
					else
					{
						// No address came back, and which of the two that means is not told apart here: the
						// location module answers a network error, a timeout and any non-200 with the same
						// empty result as a cell that has no address of its own.
						AddressQueueRepository::deleteRows($group['rows']);
					}
				}
				catch (\Throwable $e)
				{
					AddMessage2Log(
						self::logTag() . ' resolve error: ' . $e::class . ': ' . $e->getMessage()
						. ' at ' . $e->getFile() . ':' . $e->getLine(),
					);
					AddressQueueRepository::deleteRows($group['rows']);
				}

				// Every branch above takes the rows of the cell off the queue, so a handled cell is progress
				// whichever way it ended - and progress is what decides whether this pass earns a successor.
				$rowsMoved += count($group['rows']);
				$cellsDone++;
			}
		}
		finally
		{
			// Whatever ended the pass, the authors it did answer must not be left with a stale map: the
			// cache of a viewer is dropped for the addresses already applied and for nothing else.
			self::invalidateMapOfAuthors($pointDatesByAuthor);
		}

		$cellsLeft = count($groups) - $cellsDone;
		if ($cellsLeft > 0)
		{
			// A pass cut short is worth a line: with the cells of a batch cached it never happens, so the
			// line means the queue is being fed faster than the geo service answers. Cells and not places -
			// the journal is not encrypted.
			AddMessage2Log(
				self::logTag() . ': pass stopped by the time limit, ' . $cellsLeft . ' cells left queued',
			);
		}

		self::rememberSeek($rowsMoved > 0, $rows);

		if ($rowsMoved === 0)
		{
			// A pass that took nothing off the queue has earned no successor: asking for one would put the
			// very same reading back on a worker every CONTINUATION_DELAY seconds for as long as the queue
			// lives. The seek above moves the next pass past this batch, and the queue waits for it there
			// rather than spinning here.
			AddMessage2Log(
				self::logTag() . ': pass moved nothing, ' . count($rows)
				. ' rows left for the next pass past them - ' . self::reasonOfNoProgress($stoppedBy, $cellsLeft),
			);

			return false;
		}

		// Deferred rows are work left behind just as surely as cells the clock cut off: without a successor
		// they would wait for the next request that happens to open a day. The successor is worth asking for
		// only because this pass did move rows - the queue behind it is shorter, so the share of the clock
		// that fell to every remaining row is larger than it was here.
		return $cellsLeft > 0 || !empty($deferredRows) || count($rows) >= self::BATCH_SIZE;
	}

	/**
	 * The batch of this pass, taken from where the previous one left off. The seek exists only to step over
	 * a batch nothing could be done with; when it points past the end of the queue - the rows behind it were
	 * resolved by somebody else meanwhile - it is dropped and the head is taken instead, so no seek can
	 * strand the queue.
	 *
	 * @return array<int, array{CHECK_IN_ID: int, VIEWER_ID: int, USER_ID: int, POINT_DATE: int}>
	 */
	private static function takeBatch(): array
	{
		$afterCheckInId = (int)Option::get(self::MODULE_ID, self::OPTION_SEEK, '0');

		$rows = AddressQueueRepository::getBatch(self::BATCH_SIZE, $afterCheckInId);
		if (!empty($rows) || $afterCheckInId === 0)
		{
			return $rows;
		}

		Option::delete(self::MODULE_ID, ['name' => self::OPTION_SEEK]);

		return AddressQueueRepository::getBatch(self::BATCH_SIZE);
	}

	/**
	 * Where the next pass starts. Anything moved means the head of the queue has changed and is worth
	 * looking at again, so the seek goes away; nothing moved means this batch is exactly what the next pass
	 * must not take again.
	 *
	 * The seek is one CHECK_IN_ID while the key of the queue is the pair of it and the viewer, and getBatch()
	 * orders by the id alone. A batch boundary falling between two rows of the same check-in therefore leaves
	 * the rest of them behind the seek, and the next pass steps over them. They are taken as soon as any pass
	 * moves something and drops the seek: a delay of one walk of the queue, never a loss.
	 *
	 * @param array<int, array{CHECK_IN_ID: int}> $rows
	 */
	private static function rememberSeek(bool $hasMoved, array $rows): void
	{
		if ($hasMoved)
		{
			Option::delete(self::MODULE_ID, ['name' => self::OPTION_SEEK]);

			return;
		}

		$lastCheckInId = max(array_map(static fn (array $row): int => (int)$row['CHECK_IN_ID'], $rows));

		Option::set(self::MODULE_ID, self::OPTION_SEEK, (string)$lastCheckInId);
	}

	/**
	 * Why the lookup of the coordinates gave up, in words a portal journal can be read by. The distinction is
	 * the whole point of the record: addresses that stop resolving are otherwise a silent degradation - the
	 * feature simply looks broken - and the reasons want different people. The clock says this portal does
	 * not keep up with its own queue and the answer is on the side of whoever runs it. The other branch is
	 * nobody's fault yet: the day these points belong to has not been folded, so there is nothing to read
	 * for them and the rows simply wait. No place of anybody is named here: the journal is not encrypted.
	 */
	private static function reasonOfStopping(?string $stoppedBy): string
	{
		return match ($stoppedBy)
		{
			AddressQueueRepository::STOPPED_BY_CLOCK => 'the pass ran out of its time limit',
			default => 'the folded day holding them is not in the storage yet',
		};
	}

	/**
	 * Why a pass took nothing off the queue. The lookup of the coordinates is only its first phase, and the
	 * clock stops the second one too: a pass whose lookup answered for every point can still be cut in the
	 * cells before the first of them was handled, and then the reason is the clock of this portal and not
	 * anything about our budgets. Naming the lookup there would point at the wrong owner in the one record
	 * the distinction was introduced for.
	 */
	private static function reasonOfNoProgress(?string $stoppedBy, int $cellsLeft): string
	{
		if ($stoppedBy === null && $cellsLeft > 0)
		{
			return 'the pass ran out of its time limit before a single cell was answered';
		}

		return self::reasonOfStopping($stoppedBy);
	}

	private static function isTimeExceeded(int $startTime): bool
	{
		return (time() - $startTime) >= self::PASS_TIME_LIMIT;
	}

	/**
	 * Once for the whole pass and not once per cell: fifty cells of a batch usually belong to the same
	 * employee, and every drop asks HR for their heads and cleans a directory per viewer.
	 *
	 * @param array<int, array<int, true>> $pointDatesByAuthor
	 */
	private static function invalidateMapOfAuthors(array $pointDatesByAuthor): void
	{
		if (empty($pointDatesByAuthor))
		{
			return;
		}

		$localDatesByAuthor = [];
		foreach ($pointDatesByAuthor as $authorId => $pointDates)
		{
			$localDatesByAuthor[$authorId] = self::localDatesOfPoints(array_keys($pointDates));
		}

		MapDataCache::invalidateForEmployeeDates($localDatesByAuthor);
	}

	/**
	 * @param int[] $pointDates
	 * @return string[]
	 */
	private static function localDatesOfPoints(array $pointDates): array
	{
		$dates = [];
		foreach ($pointDates as $pointDate)
		{
			if ($pointDate <= 0)
			{
				continue;
			}

			// Calendar days, not multiples of a day in seconds: a day of a daylight saving change is 23 or
			// 25 hours long, and stepping by 86400 over it skips the date the viewer would ask for.
			$day = getdate($pointDate);
			foreach (self::VIEWER_DAY_SHIFTS as $shift)
			{
				$dates[date('Y-m-d', mktime(12, 0, 0, $day['mon'], $day['mday'] + $shift, $day['year']))] = true;
			}
		}

		return array_keys($dates);
	}

	/**
	 * Three outcomes, not two. A row nothing was decided about - the clock stopped the lookup before it read
	 * the day of the point, or that day is not in the storage yet - is not an orphan: its coordinates may
	 * well be there, and dropping the row would cost the point its address for good. Such rows are handed
	 * back untouched and wait for the next pass; what ends them if no pass ever can is their own age, which
	 * the lookup measures - see AddressQueueRepository::resolveOrphansFromAggregates().
	 *
	 * @param array<int, array{CHECK_IN_ID: int, VIEWER_ID: int, USER_ID: int, POINT_DATE: int}> $rows
	 * @param int $deadline unix time the lookup of the coordinates must stop at
	 * @return array{
	 *     0: array<string, array{geohash: string, latitude: float, longitude: float, rows: array<int, array>}>,
	 *     1: array<int, array>,
	 *     2: array<int, array>,
	 *     3: ?string
	 * } cells to ask about, rows with no coordinates anywhere, rows nothing was decided about, and what
	 *   cut the lookup short - AddressQueueRepository::STOPPED_BY_*, null when nothing did
	 */
	private static function groupByCell(array $rows, int $deadline): array
	{
		$pointsByCheckInId = [];
		foreach ($rows as $row)
		{
			$pointsByCheckInId[$row['CHECK_IN_ID']] = [
				'userId' => $row['USER_ID'],
				'pointDate' => $row['POINT_DATE'],
			];
		}

		['coords' => $coords, 'undecided' => $undecided, 'stoppedBy' => $stoppedBy] =
			AddressQueueRepository::getCoordsByCheckInIds($pointsByCheckInId, $deadline);

		$groups = [];
		$orphanRows = [];
		$deferredRows = [];

		foreach ($rows as $row)
		{
			$coord = $coords[$row['CHECK_IN_ID']] ?? null;
			if ($coord === null || $coord['LATITUDE'] === null || $coord['LONGITUDE'] === null)
			{
				if (isset($undecided[$row['CHECK_IN_ID']]))
				{
					$deferredRows[] = $row;

					continue;
				}

				$orphanRows[] = $row;

				continue;
			}

			// the storage keeps the coordinates in a CryptoField, so they come back as strings
			$latitude = (float)$coord['LATITUDE'];
			$longitude = (float)$coord['LONGITUDE'];

			// a place off the globe names no cell a service could answer for, and asking again would meet
			// the same value: the row leaves the queue with the ones that kept no coordinates at all
			if (!self::areCoordsOnTheGlobe($latitude, $longitude))
			{
				$orphanRows[] = $row;

				continue;
			}

			$geohash = Geohash::encode($latitude, $longitude);

			$groups[$geohash] ??= [
				'geohash' => $geohash,
				'latitude' => $latitude,
				'longitude' => $longitude,
				'rows' => [],
			];
			$groups[$geohash]['rows'][] = $row;
		}

		return [$groups, $orphanRows, $deferredRows, $stoppedBy];
	}

	private static function areCoordsOnTheGlobe(float $latitude, float $longitude): bool
	{
		return $latitude >= -90.0 && $latitude <= 90.0 && $longitude >= -180.0 && $longitude <= 180.0;
	}

	private static function resolveByCoords(string $key, float $lat, float $lon): ?string
	{
		$existing = AddressRepository::findByKey($key);
		if ($existing !== null && ($existing['ADDRESS'] ?? '') !== '')
		{
			return $existing['ADDRESS'];
		}

		$geoProvider = new GeoProvider($lat, $lon);
		$result = $geoProvider->generateAddress();

		$address = '';
		if ($result->isSuccess())
		{
			$address = $result->getData()['address'] ?? '';
		}

		if ($address === '')
		{
			$existing !== null
				? AddressRepository::markFailedByKey($key)
				: AddressRepository::createFailedByKey($key);

			return null;
		}

		$saveResult = $existing !== null
			? AddressRepository::markResolvedByKey($key, $address)
			: AddressRepository::createResolvedByKey($key, $address);

		if (!$saveResult->isSuccess())
		{
			return null;
		}

		AddressCache::setByKey($key, $address);

		return $address;
	}

	/**
	 * The address is applied to the points of this group and to nothing else: taking the whole cell would
	 * reach points of other people that this batch never looked at. The map cache of the authors is dropped
	 * by the pass, see invalidateMapOfAuthors().
	 *
	 * @param array<int, array{CHECK_IN_ID: int, VIEWER_ID: int, USER_ID: int}> $rows
	 * @return array<int, array<int, string>> recipientId => [checkinId => address]
	 */
	private static function applyAddressToGroup(CheckInRepository $repository, array $rows, string $address): array
	{
		$recipientCheckIns = [];
		$checkInIds = [];
		foreach ($rows as $row)
		{
			$checkInId = $row['CHECK_IN_ID'];
			$userId = $row['USER_ID'];
			$viewerId = $row['VIEWER_ID'];

			$checkInIds[$checkInId] = true;

			$recipientId = ($viewerId > 0) ? $viewerId : $userId;
			$recipientCheckIns[$recipientId][$checkInId] = $address;
		}

		$repository->updateAddressByIds(array_keys($checkInIds), $address);

		AddressQueueRepository::deleteRows($rows);

		return $recipientCheckIns;
	}

	/**
	 * @param array<int, array<int, string>> $resolvedByUser recipientId => [checkinId => address]
	 */
	private static function sendPushToUsers(array $resolvedByUser): void
	{
		foreach ($resolvedByUser as $userId => $checkIns)
		{
			Pull\PushSender::send(
				(int)$userId,
				Pull\PushCommand::CHECK_IN_ADDRESS_RESOLVED,
				$checkIns,
			);
		}
	}

	private static function scheduleContinuation(): void
	{
		$agentName = self::CONTINUATION_AGENT_NAME;

		\CAgent::RemoveAgent($agentName, 'stafftrack');
		\CAgent::AddAgent(
			$agentName,
			'stafftrack',
			'N',
			self::CONTINUATION_DELAY,
			'',
			'Y',
			ConvertTimeStamp(time() + self::CONTINUATION_DELAY, 'FULL'),
		);
	}
}
