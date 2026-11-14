<?php
declare(strict_types=1);

namespace Bitrix\Disk\Realtime\Events;

use Bitrix\Disk\Realtime\Event;

/**
 * Pull delivery of a docked upload queue transition (EVENT-01).
 *
 * Owner of the contract is the docked-uploader SDD; the listener is the frontend
 * docked widget on every tab of the user. The payload is a subset of DTO-01 -
 * status transitions and the folder aggregate, never per-chunk progress (the
 * exact % of an active transfer is local to the owning tab).
 *
 * Pull is a delivery improvement, not a mutex: on portals without the pull module
 * {@see Event::send()} is a no-op and tabs converge through restore instead, so
 * correctness never depends on this event being received.
 */
class DockedUploadQueueEvent extends Event
{
	public const COMMAND = 'diskDockedUploadQueueCommand';

	/**
	 * @param array{
	 *   queueId: string,
	 *   queueStatus: string,
	 *   queueDisplayStatus: string|null,
	 *   changedItems: array,
	 *   queueActions: string[],
	 *   queueQuota?: array{quotaScenario: string|null, infoHelperCode: string|null},
	 * } $payload queueStatus is the STORED status and queueDisplayStatus the composed one
	 *   the widget renders - they differ exactly where the backend composes requires_action
	 *   over a queue still pending or active, and the client must not derive one from the
	 *   other. A null queueDisplayStatus says this event composed none, and the receiving tab
	 *   keeps the display it had. queueQuota is present only when the emitting command recomputed the quota
	 *   classification ({@see \Bitrix\Disk\Internal\Service\DockedUpload\QueueEventNotifier::emit()}).
	 */
	public function __construct(array $payload)
	{
		parent::__construct(self::COMMAND, $payload);
	}

	public function sendToUser(int $userId): void
	{
		$this->send([$userId]);
	}
}
