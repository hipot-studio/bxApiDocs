<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Application;
use Bitrix\StaffTrack\Internal\Repository\AddressQueueRepository;
use Bitrix\StaffTrack\Internal\Service\CheckIn\AddressResolveQueueWorker;

class AddressResolverService
{
	private const FALLBACK_AGENT_DELAY = 30;

	/**
	 * The entry point the service had before its halves were told apart, kept for callers outside the
	 * module: it queues the points and resolves one batch in the same call. Inside the module use the
	 * halves instead - queueCheckIns() beside an answer, resolveQueued() after it has left.
	 */
	public function enqueue(array $checkIns, int $viewerId = 0): void
	{
		$this->queueCheckIns($checkIns, $viewerId);
		$this->resolveQueued();
	}

	/**
	 * Cheap half of enqueue(): stores the points in one insert per chunk and makes sure an agent will pick
	 * them up. One insert per chunk and nothing else - no geo service, no cell of the map - so a request
	 * can afford it before answering.
	 *
	 * @return bool whether the queue now holds work for these points, whether this call put it there or
	 *              an earlier one did
	 */
	public function queueCheckIns(array $checkIns, int $viewerId = 0): bool
	{
		try
		{
			return $this->processEnqueue($checkIns, $viewerId);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure('queueCheckIns', $exception);

			return false;
		}
	}

	/**
	 * Expensive half: resolves one batch of the queue right away instead of waiting for the agent. Every
	 * cell of the batch the module has no address for costs a call to the geo service, about a second of
	 * somebody else's making - so this belongs to a background job or an agent.
	 */
	public function resolveQueued(): void
	{
		try
		{
			$this->tryProcessImmediately();
		}
		catch (\Throwable $exception)
		{
			$this->logFailure('resolveQueued', $exception);
		}
	}

	/**
	 * The same pass over the queue as resolveQueued(), entered by the agent instead of by a request. Two
	 * entry points and not one because the two registrations of the agent answer the kernel differently:
	 * the fallback one is one-shot and hands whatever it could not take to the continuation agent, while
	 * the continuation hands back its own name for as long as the queue holds work. Both return the name
	 * of the agent to run next, which is what the kernel expects of an agent method.
	 *
	 * The pass itself is internal, and Infrastructure may not reach into that layer, so it is delegated
	 * from here and nothing else happens on the way.
	 */
	public function runFallbackQueuePass(): string
	{
		return AddressResolveQueueWorker::runFallback();
	}

	public function runContinuationQueuePass(): string
	{
		return AddressResolveQueueWorker::runContinuation();
	}

	private function processEnqueue(array $checkIns, int $viewerId = 0): bool
	{
		$candidates = $this->collectCandidates($checkIns);
		if (empty($candidates))
		{
			return false;
		}

		$stored = AddressQueueRepository::addMany($candidates, $viewerId);

		// a repeat of the same day adds nothing - the rows of the earlier hit are still there, and the
		// primary key refuses them. Their worker, though, may be long gone: a one-shot agent that met a
		// busy lock, or a background job that never ran. So the points that are already waiting count as
		// work too, and the agent is armed for them just the same
		if ($stored === 0 && !AddressQueueRepository::hasRowsByCheckInIds(array_keys($candidates)))
		{
			return false;
		}

		$this->scheduleAgent();

		return true;
	}

	/**
	 * The date of a point is taken from `time` and never from `dateCreate`, though both are in the payload:
	 * `dateCreate` is the moment shifted into the time zone of the employee, for showing, while the folded
	 * days the queue is answered from carry their windows in the time of the server. A point stored by the
	 * shifted value would be looked for in the day next to its own whenever the two differ across midnight.
	 *
	 * @return array<int, array{checkInId: int, userId: int, pointDate: int}>
	 */
	private function collectCandidates(array $checkIns): array
	{
		$candidates = [];

		foreach ($checkIns as $checkIn)
		{
			$latitude = (float)($checkIn['latitude'] ?? $checkIn['lat'] ?? 0);
			$longitude = (float)($checkIn['longitude'] ?? $checkIn['lon'] ?? 0);

			if (!empty($checkIn['address']) || ($latitude == 0 && $longitude == 0))
			{
				continue;
			}

			$checkInId = (int)($checkIn['id'] ?? 0);
			$userId = (int)($checkIn['userId'] ?? 0);
			$pointDate = self::pointDateOf($checkIn, $checkInId);

			if ($checkInId === 0 || $userId === 0 || $pointDate <= 0)
			{
				continue;
			}

			$candidates[$checkInId] = [
				'checkInId' => $checkInId,
				'userId' => $userId,
				'pointDate' => $pointDate,
			];
		}

		return $candidates;
	}

	/**
	 * The date has to belong to the point the id names, and for a merged marker of the timeline those two
	 * come from different halves: the id and the coordinates are those of the automatic point, while `time`
	 * is the earlier moment of the pair the marker was merged from - what the reader is shown. Queued by
	 * that, an automatic point would be looked for in the day of the manual mark whenever the two lie on
	 * different sides of midnight of the server, and the day that does not hold it answers "not there" -
	 * a final verdict, after which the row leaves the queue and the address is never resolved.
	 */
	private static function pointDateOf(array $checkIn, int $checkInId): int
	{
		$auto = $checkIn['auto'] ?? null;
		if (is_array($auto) && (int)($auto['id'] ?? 0) === $checkInId && (int)($auto['time'] ?? 0) > 0)
		{
			return (int)$auto['time'];
		}

		return (int)($checkIn['time'] ?? 0);
	}

	private function logFailure(string $method, \Throwable $exception): void
	{
		// neither half has anyone to report to: the queueing runs beside an answer that must not fail
		// because of it, the resolving runs after the answer has left. Without this record the points
		// stay addressless silently.
		// The class of the failure and the frame it was thrown in, never the trace: the frames of a trace
		// carry their scalar arguments, and the arguments of this path describe where somebody has been -
		// the journal of the portal is not encrypted at rest
		AddMessage2Log(
			'AddressResolverService::' . $method . ' error: ' . $exception::class . ': '
			. $exception->getMessage() . ' at ' . $exception->getFile() . ':' . $exception->getLine(),
		);
	}

	private function tryProcessImmediately(): bool
	{
		$connection = Application::getConnection();

		if (!$connection->lock(AddressResolveQueueWorker::QUEUE_LOCK_NAME, 0))
		{
			return false;
		}

		try
		{
			return AddressResolveQueueWorker::runOnceLocked();
		}
		finally
		{
			$connection->unlock(AddressResolveQueueWorker::QUEUE_LOCK_NAME);
		}
	}

	private function scheduleAgent(): void
	{
		$agentName = AddressResolveQueueWorker::RUN_AGENT_NAME;

		$existing = \CAgent::GetList(
			[],
			[
				'MODULE_ID' => 'stafftrack',
				'NAME' => $agentName,
			],
		)->Fetch();

		if ($existing)
		{
			return;
		}

		\CAgent::AddAgent(
			$agentName,
			'stafftrack',
			'N',
			0,
			'',
			'Y',
			ConvertTimeStamp(time() + self::FALLBACK_AGENT_DELAY, 'FULL'),
		);
	}
}
