<?php

namespace Bitrix\Calendar\Core\Queue\Rule;

use Bitrix\Calendar\Core\Base\EntityMap;
use Bitrix\Calendar\Core\Base\Map;
use Bitrix\Calendar\Core\Queue\Agent\QueueRoutingAgent;
use Bitrix\Calendar\Core\Queue\LogContext;
use Bitrix\Calendar\Core\Queue\Message\HandledMessage;
use Bitrix\Calendar\Core\Queue\Message\HandledMessageMapper;
use Bitrix\Calendar\Core\Queue\Message\Message;
use Bitrix\Calendar\Core\Queue\Message\MessageMapper;
use Bitrix\Calendar\Core\Queue\QueueListener;
use Bitrix\Calendar\Core\Queue\QueueLogger;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Psr\Log\LoggerInterface;
use Throwable;

class RuleMaster
{
	public const ON_QUEUE_PUSHED_EVENT_NAME = 'OnPushToTargetQueue';

	private const PACK_SIZE = 100;

	private const LOCK_NAME = self::class;

	private LoggerInterface $logger;

	private MessageMapper $messageMapper;

	private HandledMessageMapper $handledMessageMapper;

	private Map $routedQueues;

	public function __construct(?LoggerInterface $logger = null)
	{
		$this->logger = $logger ?? QueueLogger::get();
	}

	/**
	 * @return void
	 */
	public function run(?int $maxMessageId = null, int $afterMessageId = 0): void
	{
		$connection = Application::getConnection();
		if (!$connection->lock(self::LOCK_NAME, 0))
		{
			// The pass holding the lock took its snapshot before this call, so whatever this
			// producer has just written is out of its window and needs a signal of its own.
			$this->scheduleRecoverySafely();

			return;
		}

		$continuationAfterId = null;
		$isRecoveryNeeded = false;
		$afterMessageId = max($afterMessageId, $this->getRoutingFloorId());

		try
		{
			$maxMessageId ??= $this->getMessageMapper()->getMaxUnroutedId($afterMessageId);
			$continuationAfterId = $maxMessageId !== null
				? $this->handleMessages($maxMessageId, $afterMessageId)
				: null;
			$this->sendSystemEvents();
		}
		catch(Throwable $exception)
		{
			// An interrupted pass leaves the rest of its snapshot at status new with nobody
			// scheduled to come back for it, so the tail needs a signal of its own.
			$this->logThrowableSafely('Queue routing failed', $exception);
			$isRecoveryNeeded = true;
		}
		finally
		{
			$connection->unlock(self::LOCK_NAME);
		}

		if ($isRecoveryNeeded)
		{
			$this->scheduleRecoverySafely();
		}

		if ($continuationAfterId !== null)
		{
			try
			{
				$this->scheduleContinuation($maxMessageId, $continuationAfterId);
			}
			catch (Throwable $exception)
			{
				$this->logThrowableSafely('Queue routing continuation scheduling failed', $exception);
				$this->scheduleRecoverySafely();
			}
		}
	}

	/**
	 * A failed lookup says nothing about the queue being drained. Recovery is driven by this
	 * answer, so an unknown state has to keep it alive: a recovery agent living on a broken
	 * lookup costs a pass a minute, a dropped one costs undelivered invitations.
	 */
	public function hasUnroutedMessages(): bool
	{
		try
		{
			return $this->getMessageMapper()->getMaxUnroutedId($this->getRoutingFloorId()) !== null;
		}
		catch (Throwable $exception)
		{
			$this->logThrowableSafely('Queue routing state lookup failed', $exception);

			return true;
		}
	}

	/**
	 * @param Message $message
	 *
	 * @return bool
	 */
	private function routeMessage(Message $message): bool
	{
		$rules = Registry::getInstance()->getRules();
		$isRouted = false;
		foreach ($rules as $rule)
		{
			if ($handledMessage = $rule->route($message))
			{
				/** @var HandledMessage $handledMessage */
				$handledMessage = $this->getHandledMessageMapper()->create($handledMessage);
				$this->getRoutedQueues()->add($handledMessage->getQueue());
				$isRouted = true;
			}
		}

		return $isRouted;
	}

	/**
	 * @return int
	 */
	public function sendSystemEvents(): void
	{
		// TODO: move it to right place
		QueueListener\Dispatcher::register();

		foreach ($this->getRoutedQueues() as $queue)
		{
			$event = new Event(
				"calendar",
				self::ON_QUEUE_PUSHED_EVENT_NAME,
				[
					'queue' => $queue,
				],
			);
			EventManager::getInstance()->send($event);
		}
	}

	/**
	 * @return MessageMapper
	 */
	protected function getMessageMapper(): MessageMapper
	{
		if (empty($this->messageMapper))
		{
			$this->messageMapper = new MessageMapper();
		}

		return $this->messageMapper;
	}

	/**
	 * @return HandledMessageMapper
	 */
	private function getHandledMessageMapper(): HandledMessageMapper
	{
		if (empty($this->handledMessageMapper))
		{
			$this->handledMessageMapper = new HandledMessageMapper();
		}

		return $this->handledMessageMapper;
	}

	/**
	 * @return Map
	 */
	public function getRoutedQueues(): Map
	{
		if (empty($this->routedQueues))
		{
			$this->routedQueues = new EntityMap();
		}

		return $this->routedQueues;
	}

	public function handleMessages(?int $maxMessageId = null, int $afterMessageId = 0): ?int
	{
		$afterMessageId = max($afterMessageId, $this->getRoutingFloorId());
		$rows = $this->getMessageMapper()->getUnroutedRows(
			self::PACK_SIZE,
			$maxMessageId,
			$afterMessageId,
		);
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			try
			{
				$this->routeClaimedMessage($id, $this->getMessageMapper()->decodeRawRow($row));
			}
			catch (Throwable $exception)
			{
				try
				{
					$this->getMessageMapper()->quarantine($id);
				}
				catch (Throwable $quarantineException)
				{
					$this->logThrowableSafely('Queue message quarantine failed', $quarantineException, ['entityId' => $id]);
				}

				$this->logThrowableSafely('Queue message is not routable', $exception, ['entityId' => $id]);
			}
		}

		return count($rows) === self::PACK_SIZE
			? (int)$rows[array_key_last($rows)]['ID']
			: null;
	}

	/**
	 * The portion is read into memory once, so the drain can take a row away between that read and
	 * this call. Claiming the row with the same conditional update the drain uses, in one
	 * transaction with its handled rows, keeps the two paths from both delivering it - and keeps a
	 * handled row from outliving the raw row it points at. Nothing removes such an orphan
	 * afterwards, and a portion of them would sit at the head of the consumer selection for good.
	 */
	private function routeClaimedMessage(int $id, Message $message): void
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			if ($this->getMessageMapper()->claimForRouting($id) && !$this->routeMessage($message))
			{
				$this->getMessageMapper()->deleteById($id);
			}

			$connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$this->rollbackQuietly($connection);

			throw $exception;
		}
	}

	/**
	 * A nested rollback reaches its savepoint first and only then reports itself as unsupported, so
	 * the claim is already undone by the time that report arrives and it must not shadow the
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

	protected function scheduleContinuation(int $maxMessageId, int $afterMessageId): void
	{
		QueueRoutingAgent::schedule($maxMessageId, $afterMessageId);
	}

	protected function scheduleRecovery(): void
	{
		QueueRoutingAgent::scheduleRecovery();
	}

	private function scheduleRecoverySafely(): void
	{
		try
		{
			$this->scheduleRecovery();
		}
		catch (Throwable $exception)
		{
			$this->logThrowableSafely('Queue routing recovery scheduling failed', $exception);
		}
	}

	protected function getRoutingFloorId(): int
	{
		return RoutingFloor::get();
	}

	private function logThrowableSafely(string $message, Throwable $exception, array $context = []): void
	{
		$this->logErrorSafely($message, $context + ['exception' => LogContext::fromThrowable($exception)]);
	}

	private function logErrorSafely(string $message, array $context): void
	{
		try
		{
			$this->logger->error($message, $context);
		}
		catch (Throwable)
		{
		}
	}
}
