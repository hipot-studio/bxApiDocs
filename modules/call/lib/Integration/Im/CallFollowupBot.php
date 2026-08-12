<?php

namespace Bitrix\Call\Integration\Im;

use Bitrix\Main\Loader;
use Bitrix\Im;
use Bitrix\Im\Command;
use Bitrix\Im\Bot\Keyboard;
use Bitrix\Im\Model\CommandTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\Service\Context;
use Bitrix\ImBot;
use Bitrix\Call\Call\Registry;
use Bitrix\Call\NotifyService;
use Bitrix\Call\Integration\AI\ChatMessage;
use Bitrix\Call\Integration\AI\CallAIService;
use Bitrix\Call\Track;
use Bitrix\Call\Track\TrackService;
use Bitrix\Call\Track\CloudRecordExpectationAgent;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Analytics\CloudRecordAnalytics;

\Bitrix\Main\Loader::includeModule('imbot');

/**
 * @internal
 */
class CallFollowupBot extends ImBot\Bot\Base
{
	public const MODULE_ID = 'call';
	public const BOT_CODE = 'CallFollowupBot';
	public const COMMAND_CONTINUE_FOLLOWUP = 'continueFollowup';
	public const COMMAND_RETRY_CLOUD_RECORDING = 'retryCloudRecording';

	protected const BOT_PROPERTIES = [
		'CODE' => self::BOT_CODE,
		'TYPE' => Im\Bot::TYPE_SUPERVISOR,
		'MODULE_ID' => self::MODULE_ID,
		'CLASS' => self::class,
		'OPENLINE' => 'N', // Allow in Openline chats
		'HIDDEN' => 'Y',
		'INSTALL_TYPE' => Im\Bot::INSTALL_TYPE_SILENT,
		'METHOD_WELCOME_MESSAGE' => 'onChatStart',/** @see CallFollowupBot::onChatStart */
		'METHOD_MESSAGE_ADD' => 'onMessageAdd',/** @see CallFollowupBot::onMessageAdd */
		'METHOD_MESSAGE_UPDATE' => 'onMessageUpdate',/** @see CallFollowupBot::onMessageUpdate */
		'METHOD_MESSAGE_DELETE' => 'onMessageDelete',/** @see CallFollowupBot::onMessageDelete */
		'METHOD_BOT_DELETE' => 'onBotDelete',/** @see CallFollowupBot::onBotDelete */
		'PROPERTIES' => [
			'NAME' => 'Call Followup Bot',
			'COLOR' => 'COPILOT',
		]
	];

	//region Event

	/**
	 * @param int $messageId Message Id.
	 * @param array $messageFields Event arguments.
	 * @return bool
	 */
	public static function onCommandAdd($messageId, $messageFields): bool
	{
		$command = self::detectCommandByMessage($messageFields);
		if (!$command)
		{
			return false;
		}

		if ($messageFields['COMMAND'] === self::COMMAND_CONTINUE_FOLLOWUP)
		{
			if (preg_match("/CALL_ID:([0-9]+)/i", $messageFields['COMMAND_PARAMS'], $matches))
			{
				$callId = (int)$matches[1];
				$call = Registry::getCallWithId($callId);
				if ($call)
				{
					$result = CallAIService::getInstance()->restartCallAiTask($callId);
					if ($result->isSuccess())
					{
						$chat = Chat::getInstance($call->getChatId());
						if (
							!NotifyService::getInstance()->isMessageShown($callId, NotifyService::MESSAGE_TYPE_AI_START)
							&& NotifyService::getInstance()->findMessage($chat->getId(), $callId, NotifyService::MESSAGE_TYPE_AI_START, 1) === null
						)
						{
							$message = ChatMessage::generateTaskStartMessage($callId, $chat);
							if ($message)
							{
								$sendingConfig = (new SendingConfig())
									->enableSkipCommandExecution()
									->enableSkipCounterIncrements()
									->enableSkipUrlIndex()
								;
								$context = (new Context())->setUser($call->getInitiatorId());
								NotifyService::getInstance()
									->sendMessageDeferred($chat, $message, $sendingConfig, $context)
									->setMessageShown($callId, NotifyService::MESSAGE_TYPE_AI_START)
								;
							}
						}
					}
					else
					{
						NotifyService::getInstance()->sendTaskFailedMessage($result->getError(), $call, -1);
					}
				}
			}

			self::disableMessageButtons((int)$messageId);

			return true;
		}

		if ($messageFields['COMMAND'] === self::COMMAND_RETRY_CLOUD_RECORDING)
		{
			$log = CallAISettings::isLoggingEnable();
			$logger = Logger::getInstance();

			$disableButtons = true;

			if (preg_match("/CALL_ID:([0-9]+)/i", $messageFields['COMMAND_PARAMS'], $matches))
			{
				$callId = (int)$matches[1];
				$call = Registry::getCallWithId($callId);
				if ($call)
				{
					$log && $logger->info("CallFollowupBot: retryCloudRecording clicked. CallId: {$callId}");
					self::sendRetryButtonTelemetry($call, 'success', 'clicked');

					$trackService = TrackService::getInstance();
					$attemptedCount = 0;
					$scheduledCount = 0;
					foreach ([Track::TYPE_VIDEO_RECORD, Track::TYPE_VIDEO_PREVIEW] as $type)
					{
						foreach (Track::getTracksForCall($callId, $type) as $track)
						{
							if (!$track->getDownloadUrl() || $track->getDownloaded())
							{
								continue;
							}

							$attemptedCount++;
							self::sendRetryButtonTelemetry($call, 'success', 'track_pending', source: $track);

							$downloadResult = $trackService->downloadTrackFile($track, true);
							if ($downloadResult->isSuccess())
							{
								$scheduledCount++;
								self::sendRetryButtonTelemetry($call, 'success', 'track_scheduled', source: $track);
							}
							else
							{
								$errorCodes = [];
								foreach ($downloadResult->getErrors() as $error)
								{
									$code = $error->getCode();
									if ($code !== '' && $code !== null)
									{
										$errorCodes[] = (string)$code;
									}
								}
								$errorCode = $errorCodes ? implode(',', $errorCodes) : 'schedule_failed';

								$errors = implode('; ', $downloadResult->getErrorMessages());
								$log && $logger->error("CallFollowupBot: retryCloudRecording — downloadTrackFile failed. TrackId: {$track->getId()}, Errors: {$errors}");
								self::sendRetryButtonTelemetry($call, 'error', 'track_schedule_failed', $errorCode, $track);
							}
						}
					}

					if ($attemptedCount === 0)
					{
						$log && $logger->info("CallFollowupBot: retryCloudRecording — no retryable tracks. CallId: {$callId}");
						self::sendRetryButtonTelemetry($call, 'error', 'no_tracks', 'no_retryable_tracks');
					}
					elseif ($scheduledCount === 0)
					{
						// All download schedules failed — keep the button so the user can try again
						$log && $logger->error("CallFollowupBot: retryCloudRecording — all schedule attempts failed. CallId: {$callId}");
						self::sendRetryButtonTelemetry($call, 'error', 'schedule_failed', 'all_attempts_failed');
						$disableButtons = false;
					}
					else
					{
						CloudRecordExpectationAgent::scheduleAgent($callId);
						$log && $logger->info("CallFollowupBot: retryCloudRecording — scheduled {$scheduledCount} track(s). CallId: {$callId}");
						self::sendRetryButtonTelemetry($call, 'success', 'scheduled');
					}
				}
				else
				{
					$log && $logger->error("CallFollowupBot: retryCloudRecording — call not found. CallId: {$callId}");
				}
			}
			else
			{
				$log && $logger->error("CallFollowupBot: retryCloudRecording — CALL_ID missing in COMMAND_PARAMS");
			}

			if ($disableButtons)
			{
				self::disableMessageButtons((int)$messageId);
			}

			return true;
		}

		return false;
	}

	/**
	 * Event handler when bot join to chat.
	 *
	 * @param string $dialogId
	 * @param array $joinFields
	 *
	 * @return bool
	 */
	public static function onChatStart($dialogId, $joinFields)
	{
		return false;
	}

	/**
	 * Event handler on message add.
	 *
	 * @param int $messageId
	 * @param array $messageFields
	 *
	 * @return bool
	 */
	public static function onMessageAdd($messageId, $messageFields)
	{
		return false;
	}

	/**
	 * @param int $messageId Message Id.
	 * @param array $messageFields Event arguments.
	 *
	 * @return bool
	 */
	public static function onMessageUpdate($messageId, $messageFields): bool
	{
		return false;
	}

	/**
	 * @param int $messageId Message Id.
	 * @param array $messageFields Event arguments.
	 *
	 * @return bool
	 */
	public static function onMessageDelete($messageId, $messageFields): bool
	{
		return false;
	}

	//endregion


	//region Register

	/**
	 * Register CopilotChatBot at portal.
	 *
	 * @param array $params
	 * @return int
	 */
	public static function register(array $params = []): int
	{
		if (!Loader::includeModule('im'))
		{
			return -1;
		}

		if (self::getBotId())
		{
			return self::getBotId();
		}

		$botId = Im\Bot::register(self::BOT_PROPERTIES);
		if ($botId)
		{
			self::setBotId($botId);
			self::registerCommands();
		}

		return $botId;
	}

	/**
	 * Agent for deferred bot registration.
	 * @return string
	 */
	public static function delayRegister(int $repeat = 1): string
	{
		if (self::register() > 0 || $repeat > 100)
		{
			return '';
		}

		$repeat++;

		return __METHOD__ . "({$repeat});";
	}

	/**
	 * Unregister CopilotChatBot at portal.
	 *
	 * @return bool
	 */
	public static function unRegister(): bool
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		return Im\Bot::unRegister(['BOT_ID' => self::getBotId()]);
	}

	/**
	 * Returns command's property list.
	 * @return array{class: string, handler: string, visible: bool, context: string}[]
	 */
	public static function getCommandList(): array
	{
		$context = [
			[
				'COMMAND_CONTEXT' => 'KEYBOARD',
				'MESSAGE_TYPE' => 'C', /** @see \IM_MESSAGE_CHAT */
			],
			[
				'COMMAND_CONTEXT' => 'KEYBOARD',
				'MESSAGE_TYPE' => 'P', /** @see \IM_MESSAGE_PRIVATE */
			],
		];

		return [
			self::COMMAND_CONTINUE_FOLLOWUP => [
				'command' => self::COMMAND_CONTINUE_FOLLOWUP,
				'handler' => 'onCommandAdd',/** @see CallFollowupBot::onCommandAdd */
				'visible' => false,
				'context' => $context,
			],
			self::COMMAND_RETRY_CLOUD_RECORDING => [
				'command' => self::COMMAND_RETRY_CLOUD_RECORDING,
				'handler' => 'onCommandAdd',/** @see CallFollowupBot::onCommandAdd */
				'visible' => false,
				'context' => $context,
			],
		];
	}

	/**
	 * Registers chat commands.
	 * @return bool
	 */
	public static function registerCommands(): bool
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}
		$botId = self::getBotId();
		$commandList = [];
		$res = CommandTable::getList([
			'filter' => [
				'=MODULE_ID' => self::MODULE_ID,
				'=BOT_ID' => $botId,
			]
		]);
		while ($row = $res->fetch())
		{
			$commandList[$row['COMMAND']] = $row;
		}

		Command::clearCache();
		foreach (self::getCommandList() as $command)
		{
			if (!isset($commandList[$command['command']]))
			{
				Command::register([
					'MODULE_ID' => self::MODULE_ID,
					'BOT_ID' => $botId,
					'COMMAND' => $command['command'],
					'CLASS' => $command['CLASS'] ?? static::class,
					'METHOD_COMMAND_ADD' => 'onCommandAdd',/** @see CallFollowupBot::onCommandAdd */
					'HIDDEN' => $command['visible'] === true ? 'N' : 'Y',
					'COMMON' => 'Y',
				]);
			}
			elseif (
				($commandList[$command['command']]['CLASS'] != ($command['class'] ?? static::class))
				|| ($commandList[$command['command']]['METHOD_COMMAND_ADD'] != ($command['handler'] ?? 'onCommandAdd'))
			)
			{
				Command::update(
					['COMMAND_ID' => $commandList[$command['command']]['ID']],
					[
						'CLASS' => $command['class'] ?? static::class,
						'METHOD_COMMAND_ADD' => $command['handler'] ?? 'onCommandAdd',/** @see CallFollowupBot::onCommandAdd */
						'HIDDEN' => $command['visible'] === true ? 'N' : 'Y',
					]
				);
			}
			unset($commandList[$command['command']]);
		}
		foreach ($commandList as $command)
		{
			Command::unRegister(['COMMAND_ID' => $command['ID']]);
		}

		return true;
	}

	/**
	 * Detects command by message.
	 *
	 * @param array $message Message params.
	 * @return array|null
	 */
	protected static function detectCommandByMessage(array $message): ?array
	{
		if (
			//(isset($message['SYSTEM']) && $message['SYSTEM'] === 'Y')
			empty($message['COMMAND'])
		)
		{
			return null;
		}

		$command = self::getCommandList()[$message['COMMAND']] ?? null;
		if (!$command)
		{
			return null;
		}

		$result = null;
		foreach ($command['context'] as $context)
		{
			$diff = array_intersect_assoc($message, $context);
			if (count($diff) == count($context))
			{
				$result = $command;
				break;
			}
		}

		return $result;
	}

	//endregion

	//region Keyboard

	/**
	 * Enables keyboard buttons in message.
	 *
	 * @param int $messageId Message Id.
	 * @param bool $sendPullNotify Allow send push request.
	 *
	 * @return bool
	 */
	protected static function enableMessageButtons(int $messageId, bool $sendPullNotify = true): bool
	{
		return self::switchButtonsAvailability(true, $messageId, $sendPullNotify);
	}

	/**
	 * Disables keyboard buttons in message.
	 *
	 * @param int $messageId Message Id.
	 * @param bool $sendPullNotify Allow send push request.
	 *
	 * @return bool
	 */
	protected static function disableMessageButtons(int $messageId, bool $sendPullNotify = true): bool
	{
		return self::switchButtonsAvailability(false, $messageId, $sendPullNotify);
	}

	private static function sendRetryButtonTelemetry(
		\Bitrix\Call\Call $call,
		string $status,
		string $event,
		?string $errorCode = null,
		?Track $source = null,
	): void
	{
		(new CloudRecordAnalytics($call))->sendTelemetry(
			source: $source,
			status: $status,
			errorCode: $errorCode,
			event: 'cloud_record_retry_button_' . $event,
		);
	}

	/**
	 * Disables keyboard buttons in message.
	 *
	 * @param bool $availability Availability flat to set.
	 * @param int $messageId Message Id.
	 * @param bool $sendPullNotify Allow send push request.
	 *
	 * @return bool
	 */
	private static function switchButtonsAvailability(bool $availability, int $messageId, bool $sendPullNotify = true): bool
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}
		if ($messageId <= 0)
		{
			return false;
		}

		$message = new Message($messageId);
		if (
			!$message->getMessageId()
			|| !$message->getParams()->isSet(Params::KEYBOARD)
		)
		{
			return false;
		}

		$buttons = $message->getParams()->toArray()[Params::KEYBOARD] ?? null;
		if (!$buttons)
		{
			return false;
		}

		$keyboard = new Keyboard($buttons[0]['BOT_ID']);
		foreach ($buttons as $buttonData)
		{
			$buttonData['DISABLED'] = $availability ? 'N': 'Y';
			$keyboard->addButton($buttonData);
		}

		$message->getParams()->get(Params::KEYBOARD)->setValue($keyboard);
		$message->save();

		if ($sendPullNotify)
		{
			\CIMMessageParam::sendPull($messageId, ['KEYBOARD']);
		}

		return true;
	}
	//endregion
}
