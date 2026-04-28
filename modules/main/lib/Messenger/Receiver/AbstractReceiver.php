<?php

declare(strict_types=1);

namespace Bitrix\Main\Messenger\Receiver;

use Bitrix\Main\Application;
use Bitrix\Main\Diag\ExceptionHandlerLog;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Messenger\Broker\BrokerInterface;
use Bitrix\Main\Messenger\Entity\MessageBox;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Broker\AckFailedException;
use Bitrix\Main\Messenger\Internals\Exception\Broker\BrokerReadException;
use Bitrix\Main\Messenger\Internals\Exception\Broker\RejectFailedException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\NoLoggableException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\ProcessingException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnrecoverableMessageException;
use Exception;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal
 */
abstract class AbstractReceiver implements ReceiverInterface
{
	private const LOGGER_ID = 'main.Messenger.Receiver';

	private const DEFAULT_LIMIT = 50;

	protected int $limit = self::DEFAULT_LIMIT;

	protected string $queueId;

	protected BrokerInterface $broker;

	private ?LoggerInterface $logger = null;

	public function setLimit(int $limit): self
	{
		$this->limit = $limit > 0 ? $limit : self::DEFAULT_LIMIT;

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

	protected function getLogger(): LoggerInterface
	{
		if ($this->logger === null)
		{
			$this->logger = (new LoggerFactory())->createById(self::LOGGER_ID);
		}

		return $this->logger;
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
	 * @throws RejectFailedException
	 */
	public function run(): void
	{
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
				$this->reject($messageBox);

				Application::getInstance()->getExceptionHandler()->writeToLog(
					$e,
					ExceptionHandlerLog::CAUGHT_EXCEPTION,
				);
			}
			catch (UnrecoverableMessageException $e)
			{
				$messageBox->kill();

				$this->reject($messageBox);

				$this->getLogger()->notice(
					sprintf(
						'Message has unrecoverable case: "%s". Message: "%s" (%s). Queue: "%s". ItemId: "%s"',
						$e->getMessage(),
						$messageBox->getClassName(),
						$messageBox->getId(),
						$messageBox->getQueueId(),
						$messageBox->getItemId(),
					),
					[
						'exception' => $e,
					],
				);
			}
			catch (RecoverableMessageException $e)
			{
				$messageBox->requeue($e->getRetryDelay());

				$this->reject($messageBox);

				$this->getLogger()->debug(
					sprintf(
						'Message has recoverable case: "%s". Message: "%s" (%s). Queue: "%s". ItemId: "%s"',
						$e->getMessage(),
						$messageBox->getClassName(),
						$messageBox->getId(),
						$messageBox->getQueueId(),
						$messageBox->getItemId(),
					),
					[
						'exception' => $e,
					],
				);
			}
			catch (Throwable $e)
			{
				$this->reject($messageBox);

				if (!$e instanceof NoLoggableException)
				{
					$e = new ProcessingException($messageBox, $e);

					Application::getInstance()->getExceptionHandler()->writeToLog(
						$e,
						ExceptionHandlerLog::CAUGHT_EXCEPTION,
					);
				}
			}
		}
	}
}
