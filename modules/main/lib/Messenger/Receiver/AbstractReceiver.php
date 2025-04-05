<?php

declare(strict_types=1);

namespace Bitrix\Main\Messenger\Receiver;

use Bitrix\Main\Messenger\Broker\BrokerInterface;
use Bitrix\Main\Messenger\Entity\MessageBox;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Broker\AckFailedException;
use Bitrix\Main\Messenger\Internals\Exception\Broker\BrokerReadException;
use Bitrix\Main\Messenger\Internals\Exception\Broker\RejectFailedException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\ProcessingException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnrecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\ReceiverException;
use Exception;

/**
 * @internal
 */
abstract class AbstractReceiver implements ReceiverInterface
{
	protected int $limit = 50;

	protected string $queueId;

	protected BrokerInterface $broker;

	public function setLimit(int $limit): self
	{
		$this->limit = $limit > 0 ? $limit : 50;

		return $this;
	}

	public function setQueueId(string $queueId): self
	{
		$this->queueId = $queueId;

		return $this;
	}

	public function setBroker(BrokerInterface $broker): self
	{
		$this->broker = $broker;

		return $this;
	}

	/**
	 * @throws BrokerReadException
	 */
	protected function getMessage(): ?MessageBox
	{
		return $this->broker->getOne($this->queueId);
	}

	/**
	 * @throws BrokerReadException
	 */
	protected function getMessages(): iterable
	{
		return $this->broker->get($this->queueId, $this->limit);
	}

	/**
	 * @throws AckFailedException
	 */
	protected function ack(MessageBox $messageBox): void
	{
		$this->broker->ack($messageBox);
	}

	/**
	 * @throws RejectFailedException
	 */
	protected function reject(MessageBox $messageBox): void
	{
		$this->broker->reject($messageBox);
	}

	/**
	 * @throws Exception
	 * @throws UnprocessableMessageException
	 * @throws UnrecoverableMessageException
	 * @throws RecoverableMessageException
	 */
	abstract protected function process(MessageInterface $message): void;

	/**
	 * @throws BrokerReadException
	 * @throws ReceiverException
	 * @throws RejectFailedException
	 */
	public function run(): void
	{
		$exceptions = [];
		$messageBoxes = $this->getMessages();

		/** @var MessageBox $messageBox */
		foreach ($messageBoxes as $messageBox)
		{
			try
			{
				$this->process($messageBox->getMessage());

				$this->ack($messageBox);
			}
			catch (UnprocessableMessageException $e)
			{
				$exceptions[] = $e;

				$this->reject($messageBox);
			}
			catch (UnrecoverableMessageException $e)
			{
				$exceptions[] = $e;

				$messageBox->kill();

				$this->reject($messageBox);
			}
			catch (RecoverableMessageException $e)
			{
				$exceptions[] = $e;

				$messageBox->retry($e->getRetryDelay());

				$this->reject($messageBox);
			}
			catch (Exception $e)
			{
				$exceptions[] = new ProcessingException(
					$messageBox,
					$e->getMessage(),
					$e->getCode(),
					$e
				);

				$this->reject($messageBox);
			}
		}

		if (!empty($exceptions))
		{
			throw new ReceiverException(
				'Some messages could not be processed',
				0,
				exceptions: $exceptions
			);
		}
	}
}
