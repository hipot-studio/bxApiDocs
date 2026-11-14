<?php
namespace Bitrix\ImConnector\Provider\Base;

use Bitrix\Main;
use Bitrix\Main\Event;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Localization\Loc;

use Bitrix\ImConnector\Error;
use Bitrix\ImConnector\Result;
use Bitrix\ImConnector\Status;
use Bitrix\ImConnector\Library;
use Bitrix\ImConnector\Connector;
use Bitrix\ImConnector\Connectors;

Library::loadMessages();

class Input
{
	protected const TYPE_BITRIX24 = 'B24';
	protected const TYPE_CP = 'CP';

	/** @var Result */
	protected $result;

	protected $params = [];
	protected $command;
	protected $connector;
	protected $line;
	protected $data;

	/**
	 * Input constructor.
	 * @param array $params
	 */
	public function __construct(array $params)
	{
		$this->result = new Result();
		Library::loadMessages();
	}

	/**
	 * @return Result
	 */
	public function reception(): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$resultReceiving = $this->routing($this->command, $this->connector, $this->line, $this->data);
			if (
				!is_object($resultReceiving)
				|| !($resultReceiving instanceof Main\Result)
			)
			{
				if (!is_array($resultReceiving))
				{
					$result->setResult($resultReceiving);
				}
				else
				{
					$result->setData($resultReceiving);
				}
			}
			else
			{
				if (!$resultReceiving->isSuccess())
				{
					$result->addErrors($resultReceiving->getErrors());
				}

				$result->setData($resultReceiving->getData());
			}
		}

		return $result;
	}

	/**
	 * @param $command
	 * @param $connector
	 * @param null $line
	 * @param array $data
	 * @return Result
	 */
	public function routing($command, $connector, $line = null, $data = []): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			if (empty($command))
			{
				$result->addError(new Error(
					Loc::getMessage('IMCONNECTOR_NOT_SPECIFIED_CORRECT_COMMAND'),
					Library::ERROR_IMCONNECTOR_NOT_SPECIFIED_CORRECT_COMMAND,
					__METHOD__,
					[
						'$command' => $command,
						'$connector' => $connector,
						'$line' => $line,
						'$data' => $data
					]
				));
			}

			if (
				empty($connector)
				|| !Connector::isConnector($connector)
			)
			{
				$result->addError(new Error(
					Loc::getMessage('IMCONNECTOR_NOT_SPECIFIED_CORRECT_CONNECTOR'),
					Library::ERROR_IMCONNECTOR_NOT_SPECIFIED_CORRECT_CONNECTOR,
					__METHOD__,
					[
						'$command' => $command,
						'$connector' => $connector,
						'$line' => $line,
						'$data' => $data
					]
				));
			}
		}

		if ($result->isSuccess())
		{
			switch ($command)
			{
				case 'testConnect'://Test connection
					$result = $this->receivingTestConnect();
					break;
				case 'receivingMessage'://To receive the message
					$result = $this->receivingMessage();
					break;
				case 'receivingStatusDelivery'://To receive a delivery status
					$result = $this->receivingStatusDelivery();
					break;
				case 'receivingStatusReading'://To receive the status of reading
					$result = $this->receivingStatusReading();
					break;
				case 'receivingError':
					$result = $this->receivingError();
					break;
				case 'receivingStatusBlock':
					$result = $this->receivingStatusBlock();
					break;
				//The disconnection of the connector due to the connection with the specified data on a different portal or lines
				case 'deactivateConnector':
					$result = $this->deactivateConnector();
					break;
				//The same, limited to the binding the command names: a connector server that may issue a
				//deactivation while a reconnection is in flight sends this one instead.
				case 'deactivateConnectorBinding':
					$result = $this->deactivateConnectorBinding();
					break;
				case 'setChatName':
					$result = $this->setChatName();
					break;
				default:
					$result = $this->receivingDefault();
			}
		}

		return $result;
	}

	/**
	 * @return Result
	 */
	protected function receivingTestConnect(): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$result->setResult('OK');
		}

		return $result;
	}

	protected function setChatName(): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$lineStatus = Status::getInstance($this->connector, (int)$this->line);
			if ($lineStatus->isStatus())
			{
				$resultData = $this->processingNewChatName($this->data);

				$result->setResult($resultData);
			}
			else
			{
				$result->addError(new Error(
					Loc::getMessage('IMCONNECTOR_NOT_ACTIVE_LINE'),
					Library::ERROR_IMCONNECTOR_NOT_ACTIVE_LINE,
					__METHOD__,
					[
						'$command' => $this->command,
						'$connector' => $this->connector,
						'$line' => $this->line,
						'$data' => $this->data
					]
				));
			}
		}

		return $result;
	}

	protected function processingNewChatName($data): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$result = $this->sendEventNewChatName($data);
		}

		return $result;
	}

	protected function sendEventNewChatName($data): Result
	{
		return $this->sendEvent($data, Library::EVENT_NEW_CHAT_NAME);
	}

	/**
	 * @return Result
	 */
	protected function receivingMessage(): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$lineStatus = Status::getInstance($this->connector, (int)$this->line);
			if ($lineStatus->isStatus())
			{
				$resultData = [];
				foreach ($this->data as $cell => $message)
				{
					$resultProcessingMessage = $this->processingMessage($message);

					$resultData[$cell] = $resultProcessingMessage->getResult();
					if ($resultProcessingMessage->isSuccess())
					{
						$resultData[$cell]['SUCCESS'] = true;
					}
					else
					{
						$resultData[$cell]['SUCCESS'] = false;
						$resultData[$cell]['ERRORS'] = $resultProcessingMessage->getErrorMessages();
						//$result->addErrors($resultProcessingMessage->getErrors());
					}
				}
				$result->setResult($resultData);
			}
			else
			{
				$result->addError(new Error(
					Loc::getMessage('IMCONNECTOR_NOT_ACTIVE_LINE'),
					Library::ERROR_IMCONNECTOR_NOT_ACTIVE_LINE,
					__METHOD__,
					[
						'$command' => $this->command,
						'$connector' => $this->connector,
						'$line' => $this->line,
						'$data' => $this->data
					]
				));
			}
		}

		return $result;
	}

	/**
	 * @param $message
	 * @return Result
	 */
	protected function processingMessage($message): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			if (empty($message['type_message']))
			{
				$typeMessage = 'message';
			}
			else
			{
				$typeMessage = mb_strtolower($message['type_message']);
				unset($message['type_message']);
			}

			switch ($typeMessage)
			{
				case 'message':
					$result = $this->processingNewMessage($message);
					break;
				case 'message_update':
					$result = $this->processingUpdateMessage($message);
					break;
				case 'message_del':
					$result = $this->processingDelMessage($message);
					break;
				case 'typing_start':
					$result = $this->processingTypingStatus($message);
					break;
				case 'post':
					$result = $this->processingNewPost($message);
					break;
				case 'post_update':
					$result = $this->processingUpdatePost($message);
					break;
				case 'welcome':
					$result = $this->processingWelcomeMessage($message);
					break;
				case 'command_start':
					$result = $this->processingCommand($typeMessage, $message);

					if ($result->isSuccess())
					{
						if (
							!empty($message['ref']['source']) // has start parameter
							&& strpos($message['ref']['source'], Connectors\Base::REF_PREFIX) !== 0 // and does not begin with "btrx" prefix
						)
						{
							// display message as usual
							$this->processingNewMessage($message);
						}
					}

					break;

				default:
					$result->addError(new Error(
						Loc::getMessage('IMCONNECTOR_ERROR_PROVIDER_UNSUPPORTED_TYPE_INCOMING_MESSAGE'),
						Library::ERROR_IMCONNECTOR_PROVIDER_UNSUPPORTED_TYPE_INCOMING_MESSAGE,
						__METHOD__,
						$this->params
					));
					break;
			}
		}

		return $result;
	}

	/**
	 * @param $message
	 * @return Result
	 */
	protected function processingNewMessage($message): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$result = Connector::initConnectorHandler($this->connector)->processingInputNewMessage($message, $this->line);
		}

		if ($result->isSuccess())
		{
			$resultEvent = $this->sendEventAddMessage($result->getResult());
			if (!$resultEvent->isSuccess())
			{
				$result->addErrors($resultEvent->getErrors());
			}

			$resultData = array_merge($message, $result->getResult());
			$resultData['eventResult'] = $resultEvent->getResult();
			$result->setResult($resultData);

			// A refusal of the open line is reported to the connectors that read the per-message result:
			// without it a message nothing wrote is answered as delivered and never asked for again. The
			// command itself stays successful - the refusal belongs to this message alone, exactly as the
			// per-message SUCCESS says (@see receivingMessage()).
			if (
				in_array($this->connector, Library::ENABLE_RECEIVING_MESSAGE_RESULT, true)
				&& !self::isMessageAccepted($resultEvent->getResult())
			)
			{
				$result->addError(new Error(
					Loc::getMessage('IMCONNECTOR_ERROR_MESSAGE_NOT_ACCEPTED'),
					Library::ERROR_IMCONNECTOR_MESSAGE_NOT_ACCEPTED,
					__METHOD__,
					$message
				));
			}
		}

		return $result;
	}

	/**
	 * Whether a handler of the incoming message really wrote it.
	 *
	 * A handler that refuses the message returns `false`, and the event manager keeps such an answer out of
	 * the results altogether, so no result at all is a refusal by everybody who was asked. A handler that
	 * took the message answers with the fields of what it wrote, which is why a result naming an empty
	 * `MESSAGE_ID` is a refusal too: the message was answered for, but nothing was created.
	 *
	 * @param mixed $eventResults Answers of the handlers, @see \Bitrix\Main\Event::getResults().
	 * @return bool
	 */
	protected static function isMessageAccepted($eventResults): bool
	{
		foreach ((array)$eventResults as $eventResult)
		{
			if (
				!($eventResult instanceof Main\EventResult)
				|| $eventResult->getType() === Main\EventResult::ERROR
			)
			{
				continue;
			}

			$parameters = $eventResult->getParameters();
			if (!is_array($parameters) || empty($parameters))
			{
				continue;
			}

			if (!array_key_exists('MESSAGE_ID', $parameters) || !empty($parameters['MESSAGE_ID']))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param $message
	 * @return Result
	 */
	protected function processingUpdateMessage($message): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$result = Connector::initConnectorHandler($this->connector)->processingInputUpdateMessage($message, $this->line);
		}

		if ($result->isSuccess())
		{
			$resultEvent = $this->sendEventUpdateMessage($result->getResult());
			if (!$resultEvent->isSuccess())
			{
				$result->addErrors($resultEvent->getErrors());
			}

			$result->setResult(array_merge($message, $result->getResult()));
		}

		return $result;
	}

	/**
	 * @param $message
	 * @return Result
	 */
	protected function processingDelMessage($message): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$result = Connector::initConnectorHandler($this->connector)->processingInputDelMessage($message, $this->line);
		}

		if ($result->isSuccess())
		{
			$resultEvent = $this->sendEventDelMessage($result->getResult());
			if (!$resultEvent->isSuccess())
			{
				$result->addErrors($resultEvent->getErrors());
			}

			$result->setResult(array_merge($message, $result->getResult()));
		}

		return $result;
	}

	/**
	 * @param $message
	 * @return Result
	 */
	protected function processingTypingStatus($message): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$result = Connector::initConnectorHandler($this->connector)->processingInputTypingStatus($message, $this->line);
		}

		if ($result->isSuccess())
		{
			$resultEvent = $this->sendEventTypingStatus($result->getResult());
			if (!$resultEvent->isSuccess())
			{
				$result->addErrors($resultEvent->getErrors());
			}

			$result->setResult(array_merge($message, $result->getResult()));
		}

		return $result;
	}

	/**
	 * @param $message
	 * @return Result
	 */
	protected function processingNewPost($message): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$result = Connector::initConnectorHandler($this->connector)->processingInputNewPost($message, $this->line);
		}

		if ($result->isSuccess())
		{
			$resultEvent = $this->sendEventAddPost($result->getResult());
			if (!$resultEvent->isSuccess())
			{
				$result->addErrors($resultEvent->getErrors());
			}

			$result->setResult(array_merge($message, $result->getResult()));
		}

		return $result;
	}

	/**
	 * @param $message
	 * @return Result
	 */
	protected function processingUpdatePost($message): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$result = Connector::initConnectorHandler($this->connector)->processingInputUpdatePost($message, $this->line);
		}

		if ($result->isSuccess())
		{
			$resultEvent = $this->sendEventUpdatePost($result->getResult());
			if (!$resultEvent->isSuccess())
			{
				$result->addErrors($resultEvent->getErrors());
			}

			$result->setResult(array_merge($message, $result->getResult()));
		}

		return $result;
	}

	/**
	 * @return Result
	 */
	protected function processingWelcomeMessage($message): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$result = Connector::initConnectorHandler($this->connector)->processingInputWelcomeMessage($message, $this->line);
		}

		return $result;
	}

	/**
	 * @param string $command
	 * @param array $message
	 * @return Result
	 */
	protected function processingCommand(string $command, array $message): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$result = Connector::initConnectorHandler($this->connector)->processingInputCommand($command, $message, $this->line);

			if ($result->isSuccess())
			{
				$resultEvent = $this->sendEventCommand($command, $result->getResult());
				if (!$resultEvent->isSuccess())
				{
					$result->addErrors($resultEvent->getErrors());
				}

				$result->setResult(array_merge($message, $result->getResult()));
			}
		}

		return $result;
	}

	/**
	 * @return Result
	 */
	protected function receivingStatusDelivery(): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			foreach ($this->data as $status)
			{
				if (
					isset($status['message']['date'])
					&& !Library::isEmpty($status['message']['date'])
				)
				{
					$status['message']['date'] = DateTime::createFromTimestamp((int)$status['message']['date']);
				}

				$event = $this->sendEventStatusDelivery($status);
				if (!$event->isSuccess())
				{
					$result->addErrors($event->getErrors());
				}
			}
		}

		return $result;
	}

	/**
	 * @return Result
	 */
	protected function receivingStatusReading(): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			foreach ($this->data as $status)
			{
				$event = $this->sendEventStatusReading($status);
				if (!$event->isSuccess())
				{
					$result->addErrors($event->getErrors());
				}
			}
		}

		return $result;
	}

	/**
	 * @see \Bitrix\ImConnector\Connectors\Base::receivedError
	 * @return Result
	 */
	protected function receivingError(): Result
	{
		$result = clone $this->result;

		foreach ($this->data as $error)
		{
			$error['line'] = $this->line;

			if (!empty($error['userId']))
			{
				$user = Connector::initConnectorHandler($this->connector)->getUserByUserCode(['id' => $error['userId']]);

				if ($user->isSuccess())
				{
					$userData = $user->getResult();
					$error['user'] = $userData['ID'];
				}
				else
				{
					$result->addErrors($user->getErrors());
				}
			}

			$event = $this->sendEventError($error);
			if (!$event->isSuccess())
			{
				$result->addErrors($event->getErrors());
			}
		}

		return $result;
	}

	/**
	 * @return Result
	 */
	protected function receivingStatusBlock(): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			foreach ($this->data as $status)
			{
				$user = Connector::initConnectorHandler($this->connector)->getUserByUserCode($status['user']);

				if ($user->isSuccess())
				{
					$userData = $user->getResult();
					$status['user'] = $userData['ID'];
				}
				else
				{
					$result->addErrors($user->getErrors());
				}

				$event = $this->sendEventStatusBlock($status);
				if (!$event->isSuccess())
				{
					$result->addErrors($event->getErrors());
				}
			}
		}

		return $result;
	}

	/**
	 * @return Result
	 */
	protected function deactivateConnector(): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			Status::getInstance($this->connector, (int)$this->line)
				->setError(true)
				->save()
			;

			//Reset cache
			$cacheId = Connector::getCacheIdConnector($this->line, $this->connector);
			$cache = Cache::createInstance();
			$cache->clean($cacheId, Library::CACHE_DIR_COMPONENT);
		}

		return $result;
	}

	/**
	 * Deactivates the connector, but only while the line still holds the binding the command names
	 * (`binding_id` in its data, matched against the one kept in the line status).
	 *
	 * A deactivation travels asynchronously, so it can arrive after the line has been connected anew. The
	 * plain deactivateConnector() would then put a live line into the error state, which stops incoming
	 * messages (ERROR_IMCONNECTOR_NOT_ACTIVE_LINE) with nothing on either side saying why. A connector
	 * that stamps every connection with a binding id sends it here instead: a reconnection mints a new
	 * one, so a command issued before it no longer matches and is dropped.
	 *
	 * A command that names no binding is not acted upon at all — without one there is nothing to tell an
	 * obsolete command from a current one, which is the whole point of this method.
	 *
	 * @return Result
	 */
	protected function deactivateConnectorBinding(): Result
	{
		$result = clone $this->result;

		if (!$result->isSuccess())
		{
			return $result;
		}

		$commandBinding = is_array($this->data) ? (string)($this->data['binding_id'] ?? '') : '';
		$status = Status::getInstance($this->connector, (int)$this->line);
		$lineBinding = (string)(((array)$status->getData())['binding_id'] ?? '');

		if ($commandBinding === '' || $commandBinding !== $lineBinding)
		{
			// Answered as handled, not as failed: the sender has done its part and must not repeat it.
			return $result;
		}

		return $this->deactivateConnector();
	}

	/**
	 * @return Result
	 */
	protected function receivingBase(): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$result->addError(new Error(
				Loc::getMessage('IMCONNECTOR_ERROR_PROVIDER_DOES_NOT_SUPPORT_THIS_METHOD_CALL'),
				Library::ERROR_IMCONNECTOR_PROVIDER_DOES_NOT_SUPPORT_THIS_METHOD_CALL,
				__METHOD__,
				$this->params
			));
		}

		return $result;
	}

	/**
	 * @return Result
	 */
	protected function receivingDefault(): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$result->addError(new Error(
				Loc::getMessage('IMCONNECTOR_NOT_SPECIFIED_CORRECT_COMMAND'),
				Library::ERROR_IMCONNECTOR_NOT_SPECIFIED_CORRECT_COMMAND,
				__METHOD__,
				[
					'$command' => $this->command,
					'$connector' => $this->connector,
					'$line' => $this->line,
					'$data' => $this->data
				]
			));
		}

		return $result;
	}


	//region Incoming event

	/**
	 * @param string $command
	 * @param array $data
	 * @return Result
	 */
	protected function sendEventCommand(string $command, array $data): Result
	{
		return $this->sendEvent($data, Library::EVENT_RECEIVED_COMMAND_START);
	}

	/**
	 * @see \Bitrix\ImOpenLines\Connector::onReceivedMessage
	 * @param $data
	 * @return Result
	 */
	protected function sendEventAddMessage($data): Result
	{
		return $this->sendEvent($data, Library::EVENT_RECEIVED_MESSAGE);
	}

	/**
	 * @see \Bitrix\ImOpenLines\Connector::onReceivedMessageUpdate
	 * @param $data
	 * @return Result
	 */
	protected function sendEventUpdateMessage($data): Result
	{
		return $this->sendEvent($data, Library::EVENT_RECEIVED_MESSAGE_UPDATE);
	}

	/**
	 * @see \Bitrix\ImOpenLines\Connector::onReceivedMessageDelete
	 * @param $data
	 * @return Result
	 */
	protected function sendEventDelMessage($data): Result
	{
		return $this->sendEvent($data, Library::EVENT_RECEIVED_MESSAGE_DEL);
	}

	/**
	 * @see \Bitrix\ImOpenLines\Connector::onReceivedStatusWrites
	 * @param $data
	 * @return Result
	 */
	protected function sendEventTypingStatus($data): Result
	{
		return $this->sendEvent($data, Library::EVENT_RECEIVED_TYPING_STATUS);
	}

	/**
	 * @param $data
	 * @return Result
	 */
	protected function sendEventAddPost($data): Result
	{
		return $this->sendEvent($data, Library::EVENT_RECEIVED_POST);
	}

	/**
	 * @param $data
	 * @return Result
	 */
	protected function sendEventUpdatePost($data): Result
	{
		return $this->sendEvent($data, Library::EVENT_RECEIVED_POST_UPDATE);
	}

	/**
	 * @see \Bitrix\ImOpenLines\Connector::onReceivedStatusDelivery
	 * @param $data
	 * @return Result
	 */
	protected function sendEventStatusDelivery($data): Result
	{
		return $this->sendEvent($data, Library::EVENT_RECEIVED_STATUS_DELIVERY);
	}

	/**
	 * @param $data
	 * @return Result
	 */
	protected function sendEventStatusReading($data): Result
	{
		return $this->sendEvent($data, Library::EVENT_RECEIVED_STATUS_READING);
	}

	/**
	 * @see \Bitrix\ImOpenLines\Connector::OnReceivedError
	 * @param $data
	 * @return Result
	 */
	protected function sendEventError($data): Result
	{
		return $this->sendEvent($data, Library::EVENT_RECEIVED_ERROR);
	}

	/**
	 * @param $data
	 * @return Result
	 */
	protected function sendEventStatusBlock($data): Result
	{
		return $this->sendEvent($data, Library::EVENT_RECEIVED_STATUS_BLOCK);
	}

	/**
	 * @param $data
	 * @param string $eventName
	 * @return Result
	 */
	protected function sendEvent($data, string $eventName): Result
	{
		$result = clone $this->result;

		if ($result->isSuccess())
		{
			$data['connector'] = $this->connector;
			$data['line'] = $this->line;
			$event = new Event(Library::MODULE_ID, $eventName, $data);
			$event->send();

			$result->setResult($event->getResults());
		}

		return $result;
	}
	//endregion
}
