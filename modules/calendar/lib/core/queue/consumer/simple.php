<?php

namespace Bitrix\Calendar\Core\Queue\Consumer;

use Bitrix\Calendar\Core\Queue\Interfaces;
use Bitrix\Calendar\Core\Queue\Message\HandledMessage;
use Bitrix\Calendar\Core\Queue\Message\HandledMessageMapper;
use Bitrix\Calendar\Core\Queue\Queue\QueueRegistry;
use Bitrix\Calendar\Core;
use Bitrix\Calendar\Internals\QueueHandledMessageTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;

class Simple implements Interfaces\Consumer
{
	private const HANDLED_MESSAGE_HEADER_NAME = '~handledMessageId';
	private const DRAINED_QUEUE_NAME = 'SendingEmailNotification';

	private Interfaces\Queue $queue;

	private ?HandledMessageMapper $handledMessageMapper = null;
	protected Core\Base\Map $handledMessageMap;
	private int $packSize = 100;

	public function __construct(Interfaces\Queue $queue)
	{
		$this->queue = $queue;
	}

	public function getQueue(): Interfaces\Queue
	{
		return $this->queue;
	}

	/**
	 * @return Interfaces\Message|null
	 *
	 * @throws Core\Base\BaseException
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function receive(): ?Interfaces\Message
	{
		$handledMessageMap = $this->getHandledMessageMap();
		$isOwnershipCheckNeeded = $this->isQueueDrainedByTheMigration();

		// The portion is read into memory once and worked through for seconds, so the drain can take a
		// work item away meanwhile by deleting its handled row. Reading that row again narrows the
		// window down to the gap between the check and the send; it does not close it. A taken row is
		// stepped over rather than reported as the end of the queue, which would hide the rest of it.
		/** @var HandledMessage $row */
		while ($row = $handledMessageMap->fetch())
		{
			if ($isOwnershipCheckNeeded && !$this->isHandledMessagePresent((int)$row->getId()))
			{
				continue;
			}

			$message = $row->getMessage();
			$message->setHeader(self::HANDLED_MESSAGE_HEADER_NAME, $row->getId());

			return $message;
		}

		return null;
	}

	public function acknowledge(Interfaces\Message $message): void
	{
		if ($id = $message->getHeader(self::HANDLED_MESSAGE_HEADER_NAME))
		{
			$message->setHeader(self::HANDLED_MESSAGE_HEADER_NAME, null);
			$this->deleteHandledMessageByMessageId($id);
		}
	}

	/**
	 * @param Interfaces\Message $message
	 * @param bool $requeue
	 *
	 * @return void
	 */
	public function reject(Interfaces\Message $message, bool $requeue = false): void
	{
		if ($id = $message->getHeader(self::HANDLED_MESSAGE_HEADER_NAME))
		{
			$message->setHeader(self::HANDLED_MESSAGE_HEADER_NAME, null);
			$this->deleteHandledMessageByMessageId($id);

			$this->onAfterReject($message);
		}
	}

	/**
	 * @return Core\Base\Map
	 *
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	private function getHandledMessageMap(): Core\Base\Map
	{
		if (empty($this->handledMessageMap))
		{
			$this->handledMessageMap = $this->getHandledMessageMapper()->getMap(
				[
					'QUEUE_ID' => $this->getQueue()->getQueueId(),
					// A row whose raw message is gone cannot be built into a work item and is
					// dropped - but only after the limit has already counted it in. Left to the
					// limit, a portion of them would hide every later work item for good.
					'!=MESSAGE.ID' => null,
				],
				$this->getPackSize(),
				[
					'DATE_CREATE' => 'ASC'
				],
			);
		}

		return $this->handledMessageMap;
	}

	/**
	 * The migration drains the invitation queue and nothing else: a message of another routing key is
	 * let go before anything is claimed, and the draining status by which the drain picks rows up again
	 * is one it sets itself. Nothing can be taken from under a consumer of any other queue, so it must
	 * not pay a count per work item - a portion holds a hundred - to be told as much. Only the agent of
	 * the invitation queue is wired to this class today, the other five keep to GroupHash.
	 *
	 * A queue that cannot be told apart is checked all the same: a needless count costs one query, a
	 * missing one delivers an invitation twice.
	 *
	 * Scaffolding of the migration - it goes together with the check itself once the update is out.
	 */
	private function isQueueDrainedByTheMigration(): bool
	{
		$drainedQueueId = QueueRegistry::getIdByName(self::DRAINED_QUEUE_NAME);

		return $drainedQueueId === null || $this->getQueue()->getQueueId() === $drainedQueueId;
	}

	/**
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	private function isHandledMessagePresent(int $id): bool
	{
		return QueueHandledMessageTable::getCount(['=ID' => $id]) > 0;
	}

	/**
	 * @return HandledMessageMapper
	 */
	private function getHandledMessageMapper(): HandledMessageMapper
	{
		if ($this->handledMessageMapper === null)
		{
			$this->handledMessageMapper = new HandledMessageMapper();
		}

		return $this->handledMessageMapper;
	}

	/**
	 * @param Interfaces\Message $message
	 *
	 * @return void
	 */
	private function onAfterReject(Interfaces\Message $message)
	{
		// TODO: implement it
	}

	private function deleteHandledMessageByMessageId($id)
	{
		$handledMessage = (new HandledMessage())->setId($id);
		$this->getHandledMessageMapper()->delete($handledMessage);
	}

	/**
	 * @return int
	 */
	public function getPackSize(): int
	{
		return $this->packSize;
	}

	/**
	 * @param int $packSize
	 */
	public function setPackSize(int $packSize): void
	{
		$this->packSize = $packSize;
	}
}
