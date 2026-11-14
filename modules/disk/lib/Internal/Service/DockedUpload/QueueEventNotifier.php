<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Realtime\Events\DockedUploadQueueEvent;
use Throwable;

/**
 * Single seam through which the docked upload commands (P3) emit the pull
 * transition event (EVENT-01) to the acting user's tabs.
 *
 * Keeping the payload shape in one place avoids duplicating it across the
 * commands. Delivery is strictly best-effort: pull is an eventually-consistent
 * improvement, not a correctness dependency, so a missing pull module or any
 * push failure is swallowed and never breaks the command that triggered it.
 */
class QueueEventNotifier
{
	/**
	 * @param array<int, array> $changedItems item DTO arrays (subset of DTO-01),
	 *   including the folder-items whose aggregate the change moved
	 * @param string[] $queueActions recomputed queue-level actions; a mutation that
	 *   touches one element still changes them (the first failure raises retryFailed),
	 *   and without them the receiving tab keeps the list it got on create
	 * @param array{quotaScenario: string|null, infoHelperCode: string|null}|null $queueQuota
	 *   recomputed queue-level quota classification, as
	 *   {@see \Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto::quotaPayload()} builds it -
	 *   the commit that stops the queue by quota arrives through updateItem, so without it
	 *   the receiving tab keeps the classification it got on create (AC-030 / AC-031).
	 *   Null for a command that has not recomputed it (dismiss): the key is then absent
	 *   from the payload rather than null-valued, because a null quotaScenario is itself a
	 *   verdict ("no quota stop" - the box CTA), and the receiving tab must tell it from
	 *   "this event says nothing about the quota" and keep what it had. A folder-tree
	 *   build does pass it: it runs the same {@see QueueChangeProjector} over the queue
	 *   rows, so the value is computed, never guessed.
	 * @param string|null $queueDisplayStatus the DISPLAYED queue status, composed by
	 *   {@see DisplayStatusResolver::resolveQueueDisplayStatus()} at the moment of the emit -
	 *   the same value {@see \Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto} carries.
	 *   Unlike the quota pair the key is always sent, but its null is a verdict of absence
	 *   in the same sense CONTRACT-API-04 gives its own nullable verdict: "this event
	 *   composed no display status", not "the queue displays as nothing". The receiving tab
	 *   keeps the display it had.
	 */
	public function emit(
		int $userId,
		string $queueId,
		string $queueStatus,
		array $changedItems = [],
		array $queueActions = [],
		?array $queueQuota = null,
		?string $queueDisplayStatus = null,
	): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$payload = [
			'queueId' => $queueId,
			'queueStatus' => $queueStatus,
			// The DISPLAYED status travels beside the stored one, never instead of it: since
			// requires_action can be composed over a queue stored pending or active
			// ({@see DisplayStatusResolver::resolveQueueDisplayStatus()}), a tab told only
			// the stored value would overwrite that composition on the first transition it
			// hears about - and computing it client-side is what the "display is the
			// backend's verdict" invariant forbids. Null travels as null for the same reason:
			// substituting the stored value would dress "this caller composed nothing" up as
			// a composed verdict the tab cannot tell from a real one, and the composition it
			// already holds would be overwritten by the very status it was composed over.
			'queueDisplayStatus' => $queueDisplayStatus,
			'changedItems' => array_values($changedItems),
			'queueActions' => array_values($queueActions),
		];
		// Deliberately conditional - do NOT "even out" the payload by always sending the
		// key with a null inside. quotaScenario null is a verdict of its own ("no quota
		// stop", which the client renders as the box CTA: free up space / ask the admin), so
		// an event that never looked at the quota would overwrite a live cloud
		// classification with the box one and the banner would flip on every unrelated
		// transition. Absent key = "this event says nothing about the quota".
		if ($queueQuota !== null)
		{
			$payload['queueQuota'] = [
				'quotaScenario' => $queueQuota['quotaScenario'] ?? null,
				'infoHelperCode' => $queueQuota['infoHelperCode'] ?? null,
			];
		}

		$this->deliver($payload, $userId);
	}

	/**
	 * Delivery, kept apart from the payload this class exists to shape: the shape is a
	 * contract several commands depend on, while the send is best-effort and is exactly
	 * what a test has to stand in for.
	 *
	 * @param array<string, mixed> $payload
	 */
	protected function deliver(array $payload, int $userId): void
	{
		try
		{
			(new DockedUploadQueueEvent($payload))->sendToUser($userId);
		}
		catch (Throwable)
		{
			// Pull is a delivery improvement, not a mutex - never fail the command.
		}
	}
}
