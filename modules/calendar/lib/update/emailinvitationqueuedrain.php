<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Update;

use Bitrix\Calendar\Core\Queue\Agent\QueueRoutingAgent;
use Bitrix\Calendar\Core\Queue\LogContext;
use Bitrix\Calendar\Core\Queue\QueueLogger;
use Bitrix\Calendar\Core\Queue\Rule\RoutingFloor;
use Bitrix\Calendar\Internal\Service\Invitation\InvitationQueueFeature;
use Bitrix\Calendar\Internal\Service\Invitation\Messenger\Sender\EmailInvitationSender;
use Bitrix\Calendar\Internals\EventTable;
use Bitrix\Calendar\Internals\QueueHandledMessageTable;
use Bitrix\Calendar\Internals\QueueMessageTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Update\Stepper;
use Bitrix\Main\UserTable;
use Psr\Log\LoggerInterface;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Moves invitations that the legacy queue has not delivered yet into the messenger queue.
 *
 * Only pending rows are moved: either never routed by the legacy router (above its cursor)
 * or routed but not acknowledged yet (a handled row still exists). Everything else was
 * already delivered and must never be sent again.
 *
 * The stepper takes a row away from the other path before sending it: an unrouted row is claimed
 * by flipping its status, so the router no longer sees it; a routed one is claimed by removing its
 * handled row, so the legacy consumer no longer selects it. A claim that finds nothing to take
 * means the other path was first, and the row is left alone. A portion the consumer has already
 * read is covered by the check it makes before handing a work item out, which leaves the gap
 * between that check and the send as the only way a row still reaches both paths.
 *
 * The switched off feature keeps the backlog where it is: nothing new is claimed and the cursor
 * stands still, so the legacy path stays the one that delivers it. Only the rows already taken
 * from that path are finished, and once they are through the stepper ends - the flag is a way
 * back to the old route, not a reason to poll the tables for as long as it stays off.
 */
class EmailInvitationQueueDrain extends Stepper
{
	private const PORTION = 100;
	private const ROUTING_KEY = 'calendar:sending_email_notification';
	private const LOG_TYPE = 'email_invitation';
	private const MAX_ATTEMPTS = 3;
	private const STATUS_NEW = 'new';
	private const STATUS_DRAINING = 'draining';
	private const STATUS_DRAINED = 'drained';
	private const STATUS_QUARANTINE = 'quarantine';

	protected static $moduleId = 'calendar';

	private ?LoggerInterface $logger = null;

	private bool $isRoutingRecoveryScheduled = false;

	public function execute(array &$option): bool
	{
		if (!isset($option['maxId']))
		{
			$option['maxId'] = $this->getMaxMessageId();
		}

		$maxId = (int)$option['maxId'];
		if ($maxId <= 0)
		{
			return false;
		}

		if (!InvitationQueueFeature::isOn())
		{
			return $this->drainClaimed($option, $maxId);
		}

		$lastId = (int)($option['lastId'] ?? 0);
		$pendingIds = $this->getPendingIds($lastId, $maxId);
		if ($pendingIds === [])
		{
			$option['lastId'] = $maxId;

			return false;
		}

		$rows = $this->getMessagesByIds($pendingIds);

		foreach ($pendingIds as $messageId)
		{
			$row = $rows[$messageId] ?? null;
			if ($row !== null && !$this->drainMessageSafely($row))
			{
				$option['lastId'] = $lastId;

				return true;
			}

			$lastId = $messageId;
		}

		$option['lastId'] = $lastId;

		return $lastId < $maxId;
	}

	/**
	 * A row already claimed belongs to no other path: the legacy one has let it go and only this
	 * pass can still deliver it, so the switched off feature finishes it anyway. Everything else
	 * is left alone - the router and the legacy consumer are not switched by this flag and keep
	 * delivering the backlog whatever it says, so nothing is stranded by stopping here.
	 *
	 * The scan keeps a cursor of its own. The main one must not move while the feature is off,
	 * or the rows skipped here would end up behind it once the feature comes back - but it is
	 * still the floor this scan starts from. Behind it every claimed row is delivered already, and
	 * reaching back for one would send its invitation a second time. The rows claimed but unfinished
	 * are ahead of it, because the main pass never steps over a row it could not finish.
	 *
	 * The floor is a guard against the rows behind, not the whole answer: a row this scan finishes
	 * lies ahead of the main cursor, which stands still, and the main pass would find it again once
	 * the feature returns. What keeps that row out of both passes is its own terminal status.
	 *
	 * @return bool False once the claimed rows are through: the stepper is done, and its agent
	 *  must be removed instead of polling the tables a second apart for good.
	 */
	private function drainClaimed(array &$option, int $maxId): bool
	{
		$lastId = max(
			(int)($option['claimedLastId'] ?? 0),
			(int)($option['lastId'] ?? 0),
		);
		$claimedIds = $this->getClaimedIds($lastId, $maxId);
		if ($claimedIds === [])
		{
			return false;
		}

		$rows = $this->getMessagesByIds($claimedIds);

		foreach ($claimedIds as $messageId)
		{
			$row = $rows[$messageId] ?? null;
			if ($row !== null && !$this->drainMessageSafely($row))
			{
				$option['claimedLastId'] = $lastId;

				return true;
			}

			$lastId = $messageId;
		}

		$option['claimedLastId'] = $lastId;

		return $lastId < $maxId;
	}

	/**
	 * @return bool False when the row is worth another attempt and the pass must stop on it.
	 */
	private function drainMessageSafely(array $row): bool
	{
		try
		{
			$this->drainMessage($row);
		}
		catch (Throwable $exception)
		{
			$attempts = (int)$row['ATTEMPTS'] + 1;
			$this->registerFailure($row, $attempts, $exception);

			return $attempts >= self::MAX_ATTEMPTS;
		}

		return true;
	}

	/**
	 * A message that cannot be read is skipped for good: only sending failures are worth retrying.
	 * Nothing is claimed before the payload turns out to be ours, so a foreign message stays
	 * available to the path that owns it - and above the floor that path is the router alone, which
	 * runs on a signal from a producer this migration no longer expects. The cursor walks past such a
	 * row all the same, so the signal is given here, exactly as it is for a claim that took nothing.
	 */
	private function drainMessage(array $row): void
	{
		$messageId = (int)$row['ID'];

		try
		{
			$payload = $this->decodePayload((string)$row['MESSAGE']);
		}
		catch (Throwable $exception)
		{
			$this->log('Unreadable legacy message', $messageId, $exception);
			$this->scheduleRoutingRecoverySafely($messageId);

			return;
		}

		if (($payload['headers']['routingKey'] ?? null) !== self::ROUTING_KEY)
		{
			$this->scheduleRoutingRecoverySafely($messageId);

			return;
		}

		$invitation = $this->decodeInvitation($payload);
		if ($invitation === null)
		{
			$this->log('Unreadable invitation payload', $messageId);
			$this->scheduleRoutingRecoverySafely($messageId);

			return;
		}

		try
		{
			$isClaimed = $this->claim($messageId, (string)$row['STATUS']);
		}
		catch (Throwable $exception)
		{
			// A claim that failed took nothing, so the row still belongs to the path that has it.
			// Counted as a delivery attempt it would reach the quarantine after three failures, and a
			// quarantined row above the floor is out of the router's reach as well - nobody would
			// deliver it at all. Below the floor the path that has it is the periodic legacy consumer
			// and it comes back on its own; above the floor it is the router, which only ever runs on
			// a signal, so that signal is given here.
			$this->log('Drain failed to claim the message', $messageId, $exception);
			$this->scheduleRoutingRecoverySafely($messageId);

			return;
		}

		if (!$isClaimed)
		{
			return;
		}

		$this->getSender()->sendInvitation(
			(int)$invitation['eventId'],
			(int)$invitation['addresserId'],
			(int)$invitation['receiverId'],
			(string)$invitation['type'],
			array_column($invitation['changeFields'] ?? [], 'fieldKey'),
			$this->readSpentAttempts($invitation),
		);

		$this->markDeliveredSafely($messageId);
		$this->deleteLegacyMessageSafely($messageId);
	}

	/**
	 * The claim alone says a row is nobody else's; it does not say the invitation has gone out. Both
	 * passes read a claimed row as their own and would send it again, and neither cursor covers the
	 * other: the main one stands still while the feature is off, and the scan of the switched off
	 * feature starts no lower than it. A row ahead of the main cursor is therefore in reach of both,
	 * so the row carries the answer itself once the send is through - the terminal status is matched
	 * by no pending lookup and refused by every branch of claim().
	 *
	 * Written before the cleanup rather than after it, because the cleanup is what tends to fail:
	 * marked first, a stranded row is a delivered one. Failing here is no worse than today, and the
	 * same rule holds as for the cleanup - the invitation is already on the messenger queue, so
	 * nothing about this step may be counted as a failure to deliver.
	 */
	private function markDeliveredSafely(int $messageId): void
	{
		try
		{
			$this->markDelivered($messageId);
		}
		catch (Throwable $exception)
		{
			$this->log('Drain failed to mark the delivered message', $messageId, $exception);
		}
	}

	private function markDelivered(int $messageId): void
	{
		Application::getConnection()->queryExecute(
			(new SqlExpression(
				'UPDATE ?# SET STATUS = ?s WHERE ID = ?i',
				QueueMessageTable::getTableName(),
				self::STATUS_DRAINED,
				$messageId,
			))->compile(),
		);
	}

	/**
	 * The invitation is on the messenger queue by now, so the only work left here is cleanup, and a
	 * failure of it must not be counted as a failure to deliver. Counted as one it would take the
	 * pass back to a row the claim lets through on sight: the invitation would be sent a second and
	 * a third time, and the third failure would tell the organizer the delivery failed while the
	 * guest has had it three times over. The row is left behind instead - the claim has already taken
	 * it out of reach of the router and of the legacy consumer, and it is marked delivered before the
	 * cleanup is attempted, so no pending lookup matches it and no claim takes it. The cursor of each
	 * pass walks past it as well, but that is the weaker guard of the two: a cursor only covers what
	 * lies behind it, and the two passes keep their cursors apart.
	 */
	private function deleteLegacyMessageSafely(int $messageId): void
	{
		try
		{
			$this->deleteLegacyMessage($messageId);
		}
		catch (Throwable $exception)
		{
			$this->log('Drain failed to remove the delivered legacy message', $messageId, $exception);
		}
	}

	/**
	 * Both counters hold the attempts already spent: the legacy handler turned counterInvitation
	 * into the number of the attempt it was about to make by adding one, exactly as the receiver
	 * does with deliveryAttempt. Carried over as it is, the invitation keeps its three sends in
	 * total instead of starting them over.
	 */
	private function readSpentAttempts(array $invitation): int
	{
		$counter = $invitation['counterInvitation'] ?? 0;

		return is_numeric($counter) ? max(0, (int)$counter) : 0;
	}

	/**
	 * A row already in the draining status was claimed by an earlier step of this stepper. Any other
	 * status the two branches below refuse on their own: both are conditional on a row still owned by
	 * the path they take it from, so a delivered or quarantined row is left where it is.
	 */
	protected function claim(int $messageId, string $status): bool
	{
		if ($status === self::STATUS_DRAINING)
		{
			return true;
		}

		return $messageId > $this->getRoutingFloorId()
			? $this->claimUnroutedMessage($messageId)
			: $this->claimRoutedMessage($messageId);
	}

	/**
	 * The router picks rows up by the new status, so flipping it away from new takes the row
	 * out of its reach. A row the router has already taken is not flipped and is left to it.
	 *
	 * Standing above the floor is no proof the row was never routed: the router wrote the handled
	 * row before moving the cursor the floor was frozen from, so an interruption between the two
	 * left a row above the floor that the legacy consumer already owns. The claim revokes that row
	 * as well, in the same transaction as the flip - it is the only way the invitation stops
	 * belonging to both paths, and without it a cleanup that later fails would leave the legacy
	 * consumer free to deliver what the messenger has already been given. Finding no handled row is
	 * the ordinary case here and no reason to refuse the claim, so the flip alone decides it.
	 *
	 * Torn in half the claim would leave a row with no handled row and a status of new, which is
	 * the same dead end described in claimRoutedMessage().
	 */
	private function claimUnroutedMessage(int $messageId): bool
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$connection->queryExecute(
				(new SqlExpression(
					'UPDATE ?# SET STATUS = ?s WHERE ID = ?i AND STATUS = ?s',
					QueueMessageTable::getTableName(),
					self::STATUS_DRAINING,
					$messageId,
					self::STATUS_NEW,
				))->compile(),
			);

			$isClaimed = $connection->getAffectedRowsCount() > 0;
			if ($isClaimed)
			{
				$connection->queryExecute(
					(new SqlExpression(
						'DELETE FROM ?# WHERE MESSAGE_ID = ?i',
						QueueHandledMessageTable::getTableName(),
						$messageId,
					))->compile(),
				);
			}

			$connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$this->rollbackQuietly($connection);

			throw $exception;
		}

		return $isClaimed;
	}

	/**
	 * The legacy consumer picks rows up by their handled row and drops it on acknowledgement, so
	 * removing that row is the proof it was not acknowledged and takes it out of the later selections
	 * of the consumer. A portion already in memory is out of reach of that: there the row is left to
	 * the check the consumer makes before handing a work item out.
	 *
	 * Removing the handled row and marking the message share one transaction. Torn in half the
	 * claim would leave a row below the floor with no handled row and a status of new: the router
	 * stays above the floor, the legacy consumer has lost its work item, and none of the three
	 * pending lookups match it either, so the invitation would never be sent at all.
	 */
	private function claimRoutedMessage(int $messageId): bool
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$connection->queryExecute(
				(new SqlExpression(
					'DELETE FROM ?# WHERE MESSAGE_ID = ?i',
					QueueHandledMessageTable::getTableName(),
					$messageId,
				))->compile(),
			);

			$isClaimed = $connection->getAffectedRowsCount() > 0;
			if ($isClaimed)
			{
				$connection->queryExecute(
					(new SqlExpression(
						'UPDATE ?# SET STATUS = ?s WHERE ID = ?i',
						QueueMessageTable::getTableName(),
						self::STATUS_DRAINING,
						$messageId,
					))->compile(),
				);
			}

			$connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$this->rollbackQuietly($connection);

			throw $exception;
		}

		return $isClaimed;
	}

	/**
	 * A nested rollback reaches its savepoint first and only then reports itself as unsupported,
	 * so the claim is already undone by the time that report arrives and it must not shadow the
	 * failure the caller has to see.
	 */
	private function rollbackQuietly(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (Throwable)
		{
		}
	}

	/**
	 * A row this pass left behind - the claim took nothing, or the payload is unreadable or belongs
	 * to another queue - stays where it was while the cursor moves past it, so somebody else has to
	 * come for it. Below the floor that somebody is the periodic legacy consumer. Above it only the
	 * router can take the row, and the router runs on a signal from a producer this migration no
	 * longer expects - so the recovery agent, which re-reads everything still unrouted and keeps
	 * itself registered until the queue is drained, is asked to come instead.
	 *
	 * One signal is enough for the whole pass, and asking for more would cost a lookup in the agent
	 * registry per row: a portion can hold a hundred rows of the other calendar queues, and every one
	 * of them is a row this pass steps over.
	 *
	 * A failing registration must not become a delivery attempt of its own nor stop the pass: the
	 * row is no worse off than it was, and the backlog is read again on the next pass.
	 */
	private function scheduleRoutingRecoverySafely(int $messageId): void
	{
		if ($this->isRoutingRecoveryScheduled)
		{
			return;
		}

		try
		{
			if ($messageId > $this->getRoutingFloorId())
			{
				$this->scheduleRoutingRecovery();
				$this->isRoutingRecoveryScheduled = true;
			}
		}
		catch (Throwable $exception)
		{
			$this->log('Drain failed to schedule the routing recovery', $messageId, $exception);
		}
	}

	protected function scheduleRoutingRecovery(): void
	{
		QueueRoutingAgent::scheduleRecovery();
	}

	private function getMaxMessageId(): int
	{
		$sql = (new SqlExpression(
			'SELECT MAX(ID) AS MAX_ID FROM ?#',
			QueueMessageTable::getTableName(),
		))->compile();

		$row = Application::getConnection()->query($sql)->fetch();

		return isset($row['MAX_ID']) ? (int)$row['MAX_ID'] : 0;
	}

	/**
	 * Three narrow index-served lookups instead of one scan of the raw history: rows already
	 * claimed by this stepper, rows the router never took, and rows the legacy consumer has
	 * not acknowledged. The last group is read from the handled table, which only holds
	 * unacknowledged rows, so the bulk of the delivered history is never touched.
	 *
	 * @return int[] Ascending message ids, at most a single portion.
	 */
	private function getPendingIds(int $lastId, int $maxId): array
	{
		$floor = $this->getRoutingFloorId();

		$ids = array_merge(
			$this->getClaimedIds($lastId, $maxId),
			$this->getUnroutedIds(max($lastId, $floor), $maxId),
			$this->getUnacknowledgedIds($lastId, min($maxId, $floor)),
		);

		$ids = array_unique($ids);
		sort($ids);

		return array_slice($ids, 0, self::PORTION);
	}

	/**
	 * @return int[]
	 */
	private function getClaimedIds(int $lastId, int $maxId): array
	{
		return $this->getIdsByStatus(self::STATUS_DRAINING, $lastId, $maxId);
	}

	/**
	 * @return int[]
	 */
	private function getUnroutedIds(int $lastId, int $maxId): array
	{
		return $this->getIdsByStatus(self::STATUS_NEW, $lastId, $maxId);
	}

	/**
	 * @return int[]
	 */
	private function getIdsByStatus(string $status, int $lastId, int $maxId): array
	{
		if ($lastId >= $maxId)
		{
			return [];
		}

		$connection = Application::getConnection();
		$sql = (new SqlExpression(
			'SELECT ID FROM ?# WHERE STATUS = ?s AND ID > ?i AND ID <= ?i ORDER BY ID ASC',
			QueueMessageTable::getTableName(),
			$status,
			$lastId,
			$maxId,
		))->compile();
		$sql = $connection->getSqlHelper()->getTopSql($sql, self::PORTION);

		return array_map(
			static fn(array $row): int => (int)$row['ID'],
			$connection->query($sql)->fetchAll(),
		);
	}

	/**
	 * @return int[]
	 */
	private function getUnacknowledgedIds(int $lastId, int $ceilingId): array
	{
		if ($lastId >= $ceilingId)
		{
			return [];
		}

		$connection = Application::getConnection();
		$sql = (new SqlExpression(
			'SELECT DISTINCT MESSAGE_ID FROM ?# WHERE MESSAGE_ID > ?i AND MESSAGE_ID <= ?i'
			. ' ORDER BY MESSAGE_ID ASC',
			QueueHandledMessageTable::getTableName(),
			$lastId,
			$ceilingId,
		))->compile();
		$sql = $connection->getSqlHelper()->getTopSql($sql, self::PORTION);

		return array_map(
			static fn(array $row): int => (int)$row['MESSAGE_ID'],
			$connection->query($sql)->fetchAll(),
		);
	}

	/**
	 * @param int[] $ids
	 *
	 * @return array<int, array> Rows indexed by message id; an id without a row is an orphan
	 *  handled row and is simply stepped over.
	 */
	private function getMessagesByIds(array $ids): array
	{
		if ($ids === [])
		{
			return [];
		}

		$connection = Application::getConnection();
		$sql = (new SqlExpression(
			'SELECT ID, MESSAGE, ATTEMPTS, STATUS FROM ?# WHERE ID IN (?@) ORDER BY ID ASC',
			QueueMessageTable::getTableName(),
			$ids,
		))->compile();

		$rows = [];
		foreach ($connection->query($sql)->fetchAll() as $row)
		{
			$rows[(int)$row['ID']] = $row;
		}

		return $rows;
	}

	/**
	 * Rows above the floor were never routed, rows below it were routed and are pending
	 * only while the legacy consumer has not acknowledged them yet.
	 */
	private function getRoutingFloorId(): int
	{
		return RoutingFloor::get();
	}

	/**
	 * @throws JsonException
	 */
	private function decodePayload(string $message): array
	{
		$payload = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($payload))
		{
			throw new RuntimeException('Legacy message payload must be an array');
		}

		return $payload;
	}

	private function decodeInvitation(array $payload): ?array
	{
		$serializedInvitation = $payload['body']['requestInvitation'] ?? null;
		if (!is_string($serializedInvitation))
		{
			return null;
		}

		$serializedInvitation = str_replace("\\'", "'", $serializedInvitation);
		$serializedInvitation = Emoji::decode($serializedInvitation);
		$invitation = unserialize($serializedInvitation, ['allowed_classes' => false]);

		if (!is_array($invitation))
		{
			return null;
		}

		if (!isset(
			$invitation['eventId'],
			$invitation['addresserId'],
			$invitation['receiverId'],
			$invitation['type'],
		))
		{
			return null;
		}

		if (isset($invitation['changeFields']) && !is_array($invitation['changeFields']))
		{
			return null;
		}

		return $invitation;
	}

	private function getSender(): EmailInvitationSender
	{
		return ServiceLocator::getInstance()->get(EmailInvitationSender::class);
	}

	/**
	 * Protected as a seam for the integration test: a cleanup that fails after a successful send is
	 * otherwise not reproducible, because the ddl needed to break the delete would end the
	 * transaction of the test on its own. Same convention as claim() and scheduleRoutingRecovery().
	 */
	protected function deleteLegacyMessage(int $messageId): void
	{
		$connection = Application::getConnection();
		$connection->queryExecute(
			(new SqlExpression(
				'DELETE FROM ?# WHERE MESSAGE_ID = ?i',
				QueueHandledMessageTable::getTableName(),
				$messageId,
			))->compile(),
		);

		$connection->queryExecute(
			(new SqlExpression(
				'DELETE FROM ?# WHERE ID = ?i',
				QueueMessageTable::getTableName(),
				$messageId,
			))->compile(),
		);
	}

	private function registerFailure(array $row, int $attempts, Throwable $exception): void
	{
		$messageId = (int)$row['ID'];
		$isTerminal = $attempts >= self::MAX_ATTEMPTS;

		$sql = $isTerminal
			? new SqlExpression(
				'UPDATE ?# SET ATTEMPTS = ?i, STATUS = ?s WHERE ID = ?i',
				QueueMessageTable::getTableName(),
				$attempts,
				self::STATUS_QUARANTINE,
				$messageId,
			)
			: new SqlExpression(
				'UPDATE ?# SET ATTEMPTS = ?i WHERE ID = ?i',
				QueueMessageTable::getTableName(),
				$attempts,
				$messageId,
			);

		Application::getConnection()->queryExecute($sql->compile());

		$this->log('Drain failed to move the message', $messageId, $exception);

		if ($isTerminal)
		{
			$this->notifyOrganizerFailureSafely($row);
		}
	}

	/**
	 * A quarantined row is delivered by nobody: the claim has taken it away from the legacy path
	 * and the messenger never got it, so the organizer hears about the refusal the same way the
	 * receiver tells them about a terminal delivery failure. The quarantine is written first and
	 * keeps the row out of every pending lookup, so the notice is sent exactly once.
	 *
	 * A failing notification must not turn the terminal decision into an ordinary failure: the
	 * counter is already spent, and an escape from here would only stall the pass on a row that
	 * nothing can move any more.
	 */
	private function notifyOrganizerFailureSafely(array $row): void
	{
		try
		{
			$this->notifyOrganizerFailure($row);
		}
		catch (Throwable $exception)
		{
			$this->log('Organizer failure notification failed', (int)$row['ID'], $exception);
		}
	}

	private function notifyOrganizerFailure(array $row): void
	{
		$invitation = $this->readInvitation($row);
		if ($invitation === null)
		{
			return;
		}

		$event = $this->readEventNotificationDataSafely((int)$invitation['eventId']);
		$organizerId = $event['userId'] ?: (int)$invitation['addresserId'];
		$email = $this->readReceiverEmailSafely((int)$invitation['receiverId']);

		$this->sendOrganizerFailureNotification([
			'mode' => 'fail_ical_invite',
			'eventId' => $event['eventId'],
			'userId' => $organizerId,
			'guestId' => $organizerId,
			'items' => [['email' => $email ?? (string)$invitation['receiverId']]],
			'name' => $event['name'] ?? (string)$invitation['eventId'],
			'icalMethod' => (string)$invitation['type'],
		]);
	}

	private function readInvitation(array $row): ?array
	{
		$payload = $this->decodePayload((string)$row['MESSAGE']);
		if (($payload['headers']['routingKey'] ?? null) !== self::ROUTING_KEY)
		{
			return null;
		}

		return $this->decodeInvitation($payload);
	}

	/**
	 * The quarantine is already written and keeps the row out of every pending lookup, so this notice
	 * is the only one the organizer will ever get: a lookup that fails must cost them the name of the
	 * event, not the notice itself. The invitation carries enough to send it - its own event id and
	 * the addresser as the organizer of last resort - which is the same fallback the receiver of the
	 * live path uses.
	 */
	private function readEventNotificationDataSafely(int $eventId): array
	{
		try
		{
			return $this->getEventNotificationData($eventId);
		}
		catch (Throwable $exception)
		{
			$this->log('Drain failed to read the event of the failed invitation', $eventId, $exception);

			return ['eventId' => $eventId, 'userId' => 0, 'name' => null];
		}
	}

	private function readReceiverEmailSafely(int $receiverId): ?string
	{
		try
		{
			return $this->getReceiverEmail($receiverId);
		}
		catch (Throwable $exception)
		{
			$this->log('Drain failed to read the guest of the failed invitation', $receiverId, $exception);

			return null;
		}
	}

	protected function getEventNotificationData(int $eventId): array
	{
		$event = EventTable::query()
			->setSelect(['ID', 'PARENT_ID', 'NAME', 'MEETING_HOST'])
			->where('ID', $eventId)
			->setLimit(1)
			->fetch()
		;

		if (!is_array($event))
		{
			return ['eventId' => $eventId, 'userId' => 0, 'name' => null];
		}

		return [
			'eventId' => (int)$event['PARENT_ID'] ?: (int)$event['ID'],
			'userId' => (int)$event['MEETING_HOST'],
			'name' => (string)$event['NAME'],
		];
	}

	protected function getReceiverEmail(int $receiverId): ?string
	{
		$user = UserTable::query()
			->setSelect(['EMAIL'])
			->where('ID', $receiverId)
			->setLimit(1)
			->fetch()
		;

		$email = is_array($user) ? trim((string)$user['EMAIL']) : '';

		return $email !== '' ? $email : null;
	}

	protected function sendOrganizerFailureNotification(array $fields): void
	{
		\CCalendarNotify::Send($fields);
	}

	private function log(string $message, int $messageId, ?Throwable $exception = null): void
	{
		$context = [
			'type' => self::LOG_TYPE,
			'entityId' => $messageId,
		];
		if ($exception !== null)
		{
			$context['exception'] = LogContext::fromThrowable($exception);
		}

		// The journal lives in the database the pass is already struggling with, and registerFailure
		// journals from a catch block: an escape from here would skip the cursor save at the end of
		// execute() and leave the stepper repeating the same portion for good.
		try
		{
			$this->getLogger()->warning($message, $context);
		}
		catch (Throwable)
		{
		}
	}

	private function getLogger(): LoggerInterface
	{
		return $this->logger ??= QueueLogger::get();
	}
}
