<?php

namespace Bitrix\Imbot\Bot;

use Bitrix\AI\Context;
use Bitrix\Ai\Services\MarkdownToBBCodeTranslationService;
use Bitrix\Im\V2\Analytics\CopilotAnalytics;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Integration\AI\SimpleHistoryBuilder;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Main;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Error;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Result;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\AI;
use Bitrix\Im;
use Bitrix\ImBot;
use Bitrix\Pull;

class CopilotChatBot extends Base
{
	public const BOT_CODE = 'copilot';

	// context id for ai service
	public const
		CONTEXT_MODULE = 'im',
		CONTEXT_ID = 'copilot_chat',
		CONTEXT_SUMMARY = 'copilot_chat_summary',
		SUMMARY_PROMPT_ID = 'set_ai_session_name',
		ASSISTANT_ROLE_ID = 'copilot_assistant_chat'
	;

	// option amount of the messages to select for context
	public const
		OPTION_CONTEXT_AMOUNT = 'copilot_context_amount',
		CONTEXT_AMOUNT_DEFAULT = 25, // default value
		OPTION_MENTION_CONTEXT_AMOUNT = 'copilot_mention_context_amount',
		EXTERNAL_MENTION_CONTEXT_AMOUNT = 50 // default value for external mentions
	;

	// option mode interaction with ai service
	public const
		OPTION_MODE = 'copilot_mode',
		MODE_LONG_PULLING = 'long_pulling', // long pulling
		MODE_ASYNC_QUEUE = 'async_queue' // asynchronous requests
	;

	public const
		MESSAGE_COMPONENT_ID = 'CopilotMessage',
		MESSAGE_COMPONENT_START = 'ChatCopilotCreationMessage',
		MESSAGE_COMPONENT_COLLECTIVE = 'ChatCopilotAddedUsersMessage',
		MESSAGE_PARAMS_ERROR = 'COPILOT_ERROR',
		MESSAGE_PARAMS_MORE = 'COPILOT_HAS_MORE'
	;

	public const ALL_COPILOT_MESSAGE_COMPONENTS = [
		self::MESSAGE_COMPONENT_ID,
		self::MESSAGE_COMPONENT_START,
		self::MESSAGE_COMPONENT_COLLECTIVE,
	];

	public const
		ERROR_SYSTEM = 'SYSTEM_ERROR',
		ERROR_AGREEMENT = 'AGREEMENT_ERROR',
		ERROR_TARIFF = 'TARIFF_ERROR',
		ERROR_NETWORK = 'NETWORK',
		AI_ENGINE_ERROR_PROVIDER = 'AI_ENGINE_ERROR_PROVIDER',
		LIMIT_IS_EXCEEDED_DAILY = 'LIMIT_IS_EXCEEDED_DAILY',
		LIMIT_IS_EXCEEDED_MONTHLY = 'LIMIT_IS_EXCEEDED_MONTHLY',
		/** @see \Bitrix\AI\Limiter\Enums\ErrorLimit::BAAS_LIMIT */
		LIMIT_IS_EXCEEDED_BAAS = 'LIMIT_IS_EXCEEDED_BAAS'
	;

	protected const BOT_PROPERTIES = [
		'CODE' => self::BOT_CODE,
		'TYPE' => Im\Bot::TYPE_SUPERVISOR,
		'MODULE_ID' => self::MODULE_ID,
		'CLASS' => self::class,
		'OPENLINE' => 'N', // Allow in Openline chats
		'HIDDEN' => 'Y',
		'INSTALL_TYPE' => Im\Bot::INSTALL_TYPE_SILENT, // suppress success install message
		'METHOD_MESSAGE_ADD' => 'onMessageAdd',/** @see CopilotChatBot::onMessageAdd */
		'METHOD_MESSAGE_UPDATE' => 'onMessageUpdate',/** @see CopilotChatBot::onMessageUpdate */
		'METHOD_MESSAGE_DELETE' => 'onMessageDelete',/** @see CopilotChatBot::onMessageDelete */
		'METHOD_BOT_DELETE' => 'onBotDelete',/** @see CopilotChatBot::onBotDelete */
		'METHOD_WELCOME_MESSAGE' => 'onChatStart',/** @see CopilotChatBot::onChatStart */
	];

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
		if (!Loader::includeModule('ai'))
		{
			self::addError(new ImBot\Error(
				__METHOD__,
				self::ERROR_SYSTEM,
				Loc::getMessage('IMBOT_COPILOT_UNAVAILABLE') ?? 'Module AI is unavailable'
			));
			return -1;
		}

		if (self::getBotId())
		{
			return self::getBotId();
		}

		$languageId = Loc::getCurrentLang();
		if (!empty($params['LANG']))
		{
			$languageId = $params['LANG'];
			Loc::loadLanguageFile(__FILE__, $languageId);
		}

		$botProps = array_merge(self::BOT_PROPERTIES, [
			'LANG' => $languageId,// preferred language
			'PROPERTIES' => [
				'NAME' => Loc::getMessage('IMBOT_COPILOT_BOT_NAME', null, $languageId),
				'COLOR' => 'COPILOT',
			]
		]);

		$botAvatar = self::uploadAvatar($languageId);
		if (!empty($botAvatar))
		{
			$botProps['PROPERTIES']['PERSONAL_PHOTO'] = $botAvatar;
		}

		$botId = Im\Bot::register($botProps);
		if ($botId)
		{
			self::setBotId($botId);
		}

		$eventManager = Main\EventManager::getInstance();
		foreach (self::getEventHandlerList() as $handler)
		{
			$eventManager->registerEventHandlerCompatible(
				$handler['module'],
				$handler['event'],
				self::MODULE_ID,
				self::class,
				$handler['handler']
			);
		}

		self::restoreHistory();

		return $botId;
	}

	/**
	 * Returns event handler list.
	 * @return array{module: string, event: string, class: string, handler: string}[]
	 */
	public static function getEventHandlerList(): array
	{
		return [
			[
				'module' => 'ai',
				'event' => 'onQueueJobExecute', /** @see AI\QueueJob::EVENT_SUCCESS */
				'handler' => 'onQueueJobMessage', /** @see CopilotChatBot::onQueueJobMessage */
			],
			[
				'module' => 'ai',
				'event' => 'onQueueJobFail', /** @see AI\QueueJob::EVENT_FAIL */
				'handler' => 'onQueueJobFail', /** @see CopilotChatBot::onQueueJobFail */
			],
			[
				'module' => 'ai',
				'event' => 'onContextGetMessages', /** @see AI\Context::getMessages */
				'handler' => 'onGetContextMessages', /** @see CopilotChatBot::onGetContextMessages */
			],
		];
	}

	/**
	 * Restores chat copilot membership.
	 * @return void
	 */
	protected static function restoreHistory(): void
	{
		$chatRes = Im\Model\ChatTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=TYPE' => Im\V2\Chat::IM_TYPE_COPILOT,
				'!=AUTHOR_ID' => self::getBotId(),
			],
		]);
		while ($chatData = $chatRes->fetch())
		{
			$chat = Im\V2\Chat::getInstance((int)$chatData['ID']);
			$chat->addUsers(
				[self::getBotId()],
				new AddUsersConfig(hideHistory: true, withMessage: false, skipRecent: true)
			);
		}
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

		$eventManager = Main\EventManager::getInstance();
		foreach (self::getEventHandlerList() as $handler)
		{
			$eventManager->unregisterEventHandler(
				$handler['module'],
				$handler['event'],
				self::MODULE_ID,
				self::class,
				$handler['handler']
			);
		}

		return Im\Bot::unRegister(['BOT_ID' => self::getBotId()]);
	}

	/**
	 * Is bot enabled.
	 * @return bool
	 */
	public static function isEnabled(): bool
	{
		return
			Loader::includeModule('ai')
			&& (self::getBotId() > 0)
		;
	}

	/**
	 * Refresh settings agent.
	 * @param bool $regular
	 * @return string
	 */
	public static function refreshAgent(bool $regular = false): string
	{
		self::updateBotProperties();

		return $regular ? __METHOD__.'();' : '';
	}

	/**
	 * @return bool
	 */
	public static function updateBotProperties(): bool
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		if (!self::getBotId())
		{
			return false;
		}

		$botCache = Im\Bot::getCache(self::getBotId());

		$languageId = $botCache['LANG'] ?: Loc::getCurrentLang();
		Loc::loadLanguageFile(__FILE__, $languageId);

		$newData = array_merge(self::BOT_PROPERTIES, [
			'PROPERTIES' => [
				'NAME' => Loc::getMessage('IMBOT_COPILOT_BOT_NAME', null, $languageId),
				'COLOR' => 'COPILOT',
			]
		]);

		$avatarUrl = self::uploadAvatar($languageId);
		if ($avatarUrl)
		{
			$avatarId = \CFile::saveFile($avatarUrl, self::MODULE_ID);
			if ($avatarId)
			{
				$newData['PROPERTIES']['PERSONAL_PHOTO'] = $avatarId;
			}
		}

		Im\Bot::clearCache();
		Im\Bot::update(['BOT_ID' => self::getBotId()], $newData);

		return true;
	}

	//endregion

	//region Chat events

	/**
	 * Event handler on message add.
	 * @see \Bitrix\Im\Bot::onMessageAdd
	 *
	 * @param int $messageId Message Id.
	 * @param array $messageFields Event arguments.
	 * @return bool
	 */
	public static function onMessageAdd($messageId, $messageFields): bool
	{
		return self::processMessageAdd((int)$messageId, $messageFields, false);
	}

	private static function processMessageAdd(
		int $messageId,
		array $messageFields,
		bool $byMention
	): bool
	{
		$chat = Im\V2\Chat::getInstance((int)$messageFields['TO_CHAT_ID']);

		if (!self::checkMessageRestriction($messageFields))
		{
			return false;
		}

		if (!Loader::includeModule('ai'))
		{
			return false;
		}

		$preparedMessageFields = \CIMMessenger::prepareFieldsForMessageObject($messageFields);
		$preparedMessageFields['ID'] = $messageId;
		// small optimization: preload message params
		$targetMessage = (new Message())->fill(['PARAMS' => $preparedMessageFields['PARAMS'] ?? []]);
		$targetMessage->load($preparedMessageFields);
		if ($targetMessage->getAuthorId() === self::getBotId())
		{
			return false;
		}

		$context = self::getContext(self::CONTEXT_MODULE, self::CONTEXT_SUMMARY);
		$engine = self::getEngineByChat($chat, $context);
		$serviceRestriction = self::checkAiServeRestriction($engine, (int)$messageFields['FROM_USER_ID']);
		if (!$serviceRestriction->isSuccess())
		{
			$serviceRestrictionError = $serviceRestriction->getErrors()[0];
			self::sendError((int)$messageFields['TO_CHAT_ID'], $serviceRestrictionError->getMessage());

			(new CopilotAnalytics($chat))->addGenerate(
				$serviceRestriction,
				$byMention,
				$targetMessage
			);

			return false;
		}

		$originalMessage = (new Message())
			->setMessage($messageFields['MESSAGE_ORIGINAL'])
			->setChatId($chat->getId())
			->setChat($chat)
		;

		if ($chat->getType() === Chat::IM_TYPE_COPILOT && self::checkMessageMentions($chat, $originalMessage))
		{
			return false;
		}

		if ($chat->getType() !== Chat::IM_TYPE_COPILOT && !self::checkBotMention($originalMessage))
		{
			return false;
		}

		if ($chat->getRelationByUserId(self::getBotId()))
		{
			$messages = MessageCollection::createFromArray([$targetMessage]);
			$chat
				->withContextUser(self::getBotId())
				->readMessages($messages, true)
			;
		}

		$reasoningIsEnabled = (
			Im\V2\Application\Features::get()->isCopilotReasoningAvailable
			&& $targetMessage->getParams()->get(Message\Params::COPILOT_REASONING)->getValue()
		);

		self::sendTyping(
			(int)$messageFields['TO_CHAT_ID'],
			$reasoningIsEnabled
		);

		// send them to ai service
		$result = self::askService([
			'CHAT_ID' => $messageFields['TO_CHAT_ID'],
			'MESSAGE_ID' => $messageId,
			'MESSAGE' => $targetMessage,
			'CHAT_TYPE' => $chat->getType(),
			'CHAT' => $chat,
			'BY_MENTION' => $byMention,
		]);

		if ($result->isSuccess())
		{
			/** @var array{MESSAGE: string, HAS_MORE: bool} $output */
			if (
				($output = $result->getData())
				&& !empty($output['MESSAGE'])
			)
			{
				$message = [
					'MESSAGE' => $output['MESSAGE'],
					'PARAMS' => [
						Im\V2\Message\Params::COMPONENT_PARAMS => [
							self::MESSAGE_PARAMS_MORE => (bool)$output['HAS_MORE'],
						]
					]
				];
				self::sendMessage((int)$messageFields['TO_CHAT_ID'], $message);

				if (
					strlen($messageFields['MESSAGE']) >= 30
					&& self::isDialogHasDefaultTitle($chat)
				)
				{
					$messageFields['MESSAGE_ID'] = $messageId;
					Main\Application::getInstance()->addBackgroundJob(
						[self::class, 'getDialogMeaning'],
						[$messageFields, $chat]
					);
				}
			}
		}
		else
		{
			$error = $result->getErrors()[0];

			ImBot\Log::write(
				[
					'errorCode' => $error->getCode(),
					'errorMessage' => $error->getMessage(),
					'chatId' => $messageFields['TO_CHAT_ID'],
					'messageId' => $messageId,
				],
				'AI MESSAGE ERROR:'
			);

			self::addError(new ImBot\Error(
				__METHOD__,
				$error->getCode(),
				$error->getMessage()
			));

			$errorMessage = self::translateErrorMessage($error);
			self::sendError((int)$messageFields['TO_CHAT_ID'], $errorMessage);
		}

		return $result->isSuccess();
	}

	public static function onExternalMention(int $messageId, array $messageFields): bool
	{
		if (!self::isExternalMentionAvailable($messageFields))
		{
			return false;
		}

		return self::processMessageAdd($messageId, $messageFields, true);
	}

	private static function replaceAiMentions(?string $messageText, ?int $chatId): ?string
	{
		if (!isset($messageText, $chatId))
		{
			return '';
		}

		return Im\V2\Integration\AI\MentionService::getInstance()->replaceAiMentions($messageText, $chatId);
	}

	private static function isExternalMentionAvailable(array $fields): bool
	{
		$chat = Im\V2\Chat::getInstance((int)$fields['TO_CHAT_ID']);

		return
			!($chat instanceof Chat\ChannelChat)
			&& Loader::includeModule('im')
			&& Im\V2\Application\Features::isCopilotMentionAvailable()
		;
	}

	private static function checkMessageMentions(Im\V2\Chat $chat, Message $message): bool
	{
		if ($message->hasMentionAll())
		{
			return true;
		}

		$chatUserIds = $chat->getRelations()->getUsers()->getIds();
		unset($chatUserIds[self::getBotId()]);

		$mentionedUserIds = $message->getMentionedUserIds();

		$intersection = array_intersect($mentionedUserIds, $chatUserIds);

		return !empty($intersection);
	}

	private static function checkBotMention(Message $message): bool
	{
		$mentionedUserIds = $message->getMentionedUserIds();
		return in_array(self::getBotId(), $mentionedUserIds, true);
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
		if (!self::checkMessageRestriction($messageFields))
		{
			return false;
		}

		return true;
	}

	//endregion

	//region AI Payload

	/**
	 * Make request to AI Engine.
	 * @param array{CHAT_ID: int, MESSAGE_ID: int, MESSAGE: Message, CHAT_TYPE: string, CHAT: Im\V2\Chat, BY_MENTION: bool} $params
	 * @return Result<array{HASH: string, MESSAGE: string, HAS_MORE: bool}>
	 */
	protected static function askService(array $params): Result
	{
		$result = new Result();

		$contextParams = [
			'chat_id' => $params['CHAT_ID'],
			'message_id' => $params['MESSAGE_ID'],
			'by_mention' => $params['BY_MENTION'] ? 'Y' : 'N',
		];

		$chat = $params['CHAT'];
		$context = self::getContext(self::CONTEXT_MODULE, self::CONTEXT_ID, $contextParams);
		$engine = self::getEngineByChat($chat, $context);

		if ($engine instanceof AI\Engine)
		{
			$payload = self::fillPayload($params);

			if (self::isMemoryContextEnabled())
			{
				$engine->useMemoryContextService();
			}

			if (
				Im\V2\Application\Features::get()->isCopilotReasoningAvailable
				&& $params['MESSAGE']->getParams()->get(Message\Params::COPILOT_REASONING)->getValue()
				&& $engine->supportsReasoning()
			)
			{
				$engine->setReasoningMode(true);
			}

			$engine
				->setPayload($payload)
				->setHistoryState(false)
				->onSuccess(function (AI\Result $queueResult, ?string $queueHash = null) use($engine, &$result) {
					$isQueueable = $engine instanceof AI\Engine\IQueue;
					$message = $isQueueable ? $queueResult->getRawData() : $queueResult->getPrettifiedData();

					$rawData = $queueResult->getRawData();
					$hasMore =
						isset($rawData['choices'], $rawData['choices'][0], $rawData['choices'][0]['finish_reason'])
						&& $rawData['choices'][0]['finish_reason'] == 'length'
					;

					$result->setData([
						'HASH' => $queueHash,
						'MESSAGE' => $message,
						'HAS_MORE' => $hasMore,
					]);

				})
				->onError(function (Error $processingError) use(&$result) {
					$result->addError($processingError);
				})
			;
			if (self::getMode() == self::MODE_ASYNC_QUEUE)
			{
				$engine->completionsInQueue(); // asynchronous requests
			}
			else
			{
				$engine->completions();// long pulling
			}
		}

		(new CopilotAnalytics($params['CHAT']))->addGenerate(
			$result,
			$params['BY_MENTION'],
			$params['MESSAGE']
		);

		return $result;
	}

	protected static function fillPayload(array $params): AI\Payload\Payload
	{
		$message = $params['MESSAGE'];
		$byMention = $params['BY_MENTION'];
		$history = (new SimpleHistoryBuilder($message, self::getContextLimit($byMention)))->build();
		$payload = new AI\Payload\Text($history->toString());
		$roleManager = new Im\V2\Integration\AI\RoleManager();
		$systemChatRole = AI\Prompt\Role::get('copilot_chat_mention');
		$roleByChat = AI\Prompt\Role::get($roleManager->getMainRole($params['CHAT']->getChatId()));
		$payload->setRole($roleByChat);
		if ($systemChatRole !== null && $roleByChat?->getCode() === 'copilot_assistant')
		{
			$payload->setRole($systemChatRole);
		}

		return $payload;
	}

	/**
	 * Generates summary.
	 * @param array{CHAT_ID: int, MESSAGE_TEXT: string} $params
	 * @return Result
	 */
	protected static function extractSummary(array $params): Result
	{
		$result = new Result();

		$prompt = AI\Prompt\Manager::getByCode(self::SUMMARY_PROMPT_ID);
		if ($prompt instanceof AI\Prompt\Item)
		{
			$contextParams = ['chat_id' => $params['CHAT_ID']];
			$text = $params['MESSAGE_TEXT'];

			$context = self::getContext(self::CONTEXT_MODULE, self::CONTEXT_SUMMARY, $contextParams);

			$chat = Chat::getInstance((int)$params['CHAT_ID']);

			$engine = self::getEngineByChat($chat, $context);

			if ($engine instanceof AI\Engine)
			{
				$payload = new AI\Payload\Prompt(self::SUMMARY_PROMPT_ID);
				$payload
					->setMarkers(['original_message' => $text])
					->setRole(AI\Prompt\Role::get(Im\V2\Integration\AI\RoleManager::getDefaultRoleCode()))
				;

				$engine
					->setPayload($payload)
					->setParameters(['max_tokens' => 250])
					->setHistoryState(false)
					->onSuccess(function (AI\Result $queueResult, ?string $queueHash = null) use($engine, &$result) {
						$isQueueable = $engine instanceof AI\Engine\IQueue;
						$message = $isQueueable ? $queueResult->getRawData() : $queueResult->getPrettifiedData();

						$result->setData([
							'SUMMARY' => $message,
						]);
					})
					->onError(function (Error $processingError) use(&$result) {
						$result->addError($processingError);
					})
				;
				if (self::getMode() == self::MODE_ASYNC_QUEUE)
				{
					$engine->completionsInQueue(); // asynchronous requests
				}
				else
				{
					$engine->completions();// long pulling
				}
			}
		}

		return $result;
	}

	/**
	 * Event handler for `ai:onContextGetMessages` event.
	 * @see AI\Context::getMessages
	 * @event ai:onContextGetMessages
	 *
	 * @param string $moduleId
	 * @param string $contextId
	 * @param array $parameters
	 * @param mixed|null $nextStep
	 * @return array
	 */
	public static function onGetContextMessages($moduleId, $contextId, $parameters = [], $nextStep = null): array
	{
		return [];
	}

	private static function getChatIdFromContextParameters(array $contextParameters): ?int
	{
		$value = $contextParameters['chat_id'] ?? null;
		if (is_numeric($value))
		{
			return (int)$value;
		}

		// case when $contextParameters['chat_id'] store dialogId
		// TODO: remove it later
		if (is_string($value) && str_starts_with($value, 'chat'))
		{
			return (int)mb_substr($value, 4);
		}

		return null;
	}

	/**
	 * Success callback handler.
	 * @see AI\QueueJob::execute
	 * @event ai:onQueueJobExecute
	 *
	 * @param string $hash
	 * @param AI\Engine\IEngine $engine
	 * @param AI\Result $result
	 * @param Error|null $error
	 * @return void
	 */
	public static function onQueueJobMessage(string $hash, AI\Engine\IEngine $engine, AI\Result $result, ?Error $error): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$moduleId = $engine->getContext()->getModuleId();
		$contextId = $engine->getContext()->getContextId();
		$parameters = $engine->getContext()->getParameters();

		if (
			empty($moduleId)
			|| $moduleId != self::CONTEXT_MODULE
			|| empty($contextId)
			|| !in_array($contextId, [self::CONTEXT_ID, self::CONTEXT_SUMMARY])
			|| empty($parameters)
			|| empty($parameters['chat_id'])
		)
		{
			return;
		}

		$chatId = self::getChatIdFromContextParameters($parameters);
		if (!$chatId)
		{
			return;
		}

		$messageId = $parameters['message_id'] ?? null;
		$byMention = ($parameters['by_mention'] ?? 'N') === 'Y';

		if (!empty($result->getPrettifiedData()))
		{
			$chat = Im\V2\Chat::getInstance($chatId);

			if ($contextId == self::CONTEXT_SUMMARY)
			{
				self::renameChat($chat, $result->getPrettifiedData());

				return;
			}

			self::sendMessage($chatId, $result->getPrettifiedData());
			$message = new Message((int)$messageId);
			$messageText = $message->getMessage();

			$receiveResult = new Result();
			if ($error)
			{
				$receiveResult->addError($error);
			}

			(new CopilotAnalytics($message->getChat()))
				->withContextUser($message->getAuthorId())
				->addReceive($receiveResult, $message, $byMention)
			;

			if (!isset($messageId))
			{
				return;
			}
			if (!self::isDialogHasDefaultTitle($chat))
			{
				return;
			}

			if (is_string($messageText) && strlen($messageText) >= 30)
			{
				Main\Application::getInstance()->addBackgroundJob(
					[self::class, 'getDialogMeaning'],
					[
						['CHAT_ID' => $chatId, 'MESSAGE' => $messageText, 'MESSAGE_ID' => (int)$messageId],
						$chat,
					]
				);
			}
		}
	}

	/**
	 * Callback handler Copilot job has been failed.
	 * @see AI\QueueJob::clearOldAgent
	 * @see AI\QueueJob::fail
	 * @event ai:onQueueJobFail
	 *
	 * @param string $hash
	 * @param AI\Engine\IEngine $engine
	 * @param AI\Result $result
	 * @param Error|null $error
	 * @return void
	 */
	public static function onQueueJobFail(string $hash, AI\Engine\IEngine $engine, AI\Result $result, ?Error $error): void
	{
		$moduleId = $engine->getContext()->getModuleId();
		$contextId = $engine->getContext()->getContextId();
		$parameters = $engine->getContext()->getParameters();

		if (
			empty($moduleId)
			|| $moduleId != self::CONTEXT_MODULE
			|| empty($contextId)
			|| !in_array($contextId, [self::CONTEXT_ID, self::CONTEXT_SUMMARY])
			|| empty($parameters)
			|| empty($parameters['chat_id'])
		)
		{
			return;
		}

		$chatId = self::getChatIdFromContextParameters($parameters);
		if (!$chatId)
		{
			return;
		}

		$messageId = $parameters['message_id'] ?? null;
		$byMention = ($parameters['by_mention'] ?? 'N') === 'Y';
		if ($messageId && Loader::includeModule('im'))
		{
			$receiveResult = new Result();
			if ($error)
			{
				$receiveResult->addError($error);
			}
			$message = new Message($messageId);
			(new CopilotAnalytics($message->getChat()))
				->withContextUser($message->getAuthorId())
				->addReceive($receiveResult, $message, $byMention)
			;
		}

		ImBot\Log::write(
			[
				'errorMessage' => $error ? $error->getMessage() : 'Job fail',
				'errorCode' => $error ? $error->getCode() : '',
				'hash' => $hash,
				'params' => $parameters,
			],
			'AI QUEUE FAIL:'
		);

		if ($contextId == self::CONTEXT_ID)
		{
			$errorMessage = self::translateErrorMessage($error);
			self::sendError($chatId, $errorMessage);
		}
	}

	protected static function translateErrorMessage(?Error $error): string
	{
		$customData = $error?->getCustomData();
		$defaultMessage = Loc::getMessage('IMBOT_COPILOT_JOB_FAIL_MSGVER_1');

		$message = match (true)
		{
			$error === null || is_numeric($error->getCode()) || $error->getCode() === 'HASH_EXPIRED' => $defaultMessage,
			is_array($customData) && isset($customData['msgForIm']) => $customData['msgForIm'],
			$error->getCode() === self::LIMIT_IS_EXCEEDED_BAAS => Loc::getMessage(
				'IMBOT_COPILOT_ERROR_LIMIT_BAAS',
				['#LINK#' => '/online/?FEATURE_PROMOTER=limit_boost_copilot']
			),
			$error->getCode() === 'NETWORK' => Loc::getMessage('IMBOT_COPILOT_ERROR_NETWORK_MSGVER_1'),
			(bool)$error->getMessage() => $error->getMessage(),
			default => $defaultMessage,
		};

		return is_string($message) ? $message : $defaultMessage;
	}

	public static function getEngineByChat(Im\V2\Chat $chat, ?Context $context = null): ?AI\Engine
	{
		$engine = null;

		if ($chat instanceof Im\V2\Chat\GroupChat)
		{
			$engineCode = $chat->getEngineCode();
			$engine = (new Im\V2\Integration\AI\EngineManager())->getEngineByCode($engineCode, $context);
		}

		if (!isset($engine))
		{
			$engine = Im\V2\Integration\AI\EngineManager::getDefaultEngine($context);
		}

		return $engine;
	}

	protected static function getContext(string $moduleId, string $contextId, array $parameters = []): Context
	{
		$context = new Context($moduleId, $contextId);

		if (!empty($parameters))
		{
			$context->setParameters($parameters);
		}

		return $context;
	}
	//endregion

	//region Restrictions

	/**
	 * Check service AI unavailability and restrictions.
	 * @return Result
	 */
	protected static function checkAiServeRestriction(?AI\Engine $engine, int $currentUserId): Result
	{
		$checkResult = new Result();
		if (Loader::includeModule('ai'))
		{
			if ($engine instanceof AI\Engine)
			{
				if (!$engine->isAvailableByAgreement())
				{
					$checkResult->addError(self::getErrorMessageOnTariffRestriction($currentUserId));
				}
				elseif (!$engine->isAvailableByTariff())
				{
					$checkResult->addError(new Error(
						Loc::getMessage('IMBOT_COPILOT_TARIFF_RESTRICTION') ?? 'AI service unavailable by tariff',
						self::ERROR_TARIFF
					));
				}
			}
			else
			{
				$checkResult->addError(new Error(
					Loc::getMessage('IMBOT_COPILOT_UNAVAILABLE') ?? 'Module AI is unavailable',
					self::ERROR_SYSTEM
				));
			}
		}
		else
		{
			$checkResult->addError(new Error(
				Loc::getMessage('IMBOT_COPILOT_UNAVAILABLE') ?? 'Module AI is unavailable',
				self::ERROR_SYSTEM
			));
		}

		return $checkResult;
	}

	protected static function getErrorMessageOnTariffRestriction(int $currentUserId): Error
	{
		$isB24 = Main\ModuleManager::isModuleInstalled('bitrix24');
		if (method_exists(AI\Facade\Bitrix24::class, 'shouldUseB24'))
		{
			$isB24 = AI\Facade\Bitrix24::shouldUseB24();
		}

		if (!$isB24)
		{
			return new Error(
				Loc::getMessage('IMBOT_COPILOT_AGREEMENT_RESTRICTION_BOX', [
					'#LINK#' => '/online/?AI_UX_TRIGGER=box_agreement',
				]) ?? 'AI service agreement must be accepted',
				self::ERROR_AGREEMENT
			);
		}

		if (
			Loader::includeModule('bitrix24')
			&& \CBitrix24::IsPortalAdmin($currentUserId)
		)
		{
			return new Error(
				Loc::getMessage('IMBOT_COPILOT_AGREEMENT_RESTRICTION_ADMIN', [
					'#LINK#' => '/',
				]) ?? 'AI service agreement must be accepted',
				self::ERROR_AGREEMENT
			);
		}

		return new Error(
			Loc::getMessage('IMBOT_COPILOT_AGREEMENT_RESTRICTION_USER') ?? 'AI service agreement must be accepted',
			self::ERROR_AGREEMENT
		);
	}

	/**
	 * Put here any restriction for chat membership.
	 *
	 * @param array $messageFields
	 * @return bool
	 */
	protected static function checkMembershipRestriction(Im\V2\Chat $chat, array $messageFields): bool
	{
		if (
			!($chat instanceof Im\V2\Chat\CopilotChat)
			|| $messageFields['MESSAGE_TYPE'] != Im\V2\Chat::IM_TYPE_COPILOT
		)
		{
			return false;
		}

		return true;
	}

	/**
	 * Put here any restriction for message type length.
	 * @param array $messageFields
	 * @return bool
	 */
	protected static function checkMessageRestriction(array $messageFields): bool
	{
		if (isset($messageFields['SYSTEM']) && $messageFields['SYSTEM'] == 'Y')
		{
			return false;
		}
		if (isset($messageFields['PARAMS']['FORWARD_CONTEXT_ID']))
		{
			return false;
		}

		return !empty($messageFields['MESSAGE']);
	}

	//endregion

	//region Title

	/**
	 * Do we need to rename copilot chat.
	 *
	 * @param Im\V2\Chat $chat
	 * @return bool
	 */
	protected static function isDialogHasDefaultTitle(Im\V2\Chat $chat): bool
	{
		if ($chat instanceof Im\V2\Chat\CopilotChat)
		{
			if ($template = Im\V2\Chat\CopilotChat::getTitleTemplate())
			{
				$template = strtr($template, ['#NUMBER#' => '[0-9]+']);
				return preg_match("/{$template}/", $chat->getTitle());
			}
		}

		return false;
	}

	/**
	 * Rename copilot chat.
	 *
	 * @param array $messageFields
	 * @param Im\V2\Chat $chat
	 * @return void
	 */
	public static function getDialogMeaning(array $messageFields, Im\V2\Chat $chat): void
	{
		$resultTitle = self::extractSummary([
			'CHAT_ID' => $messageFields['CHAT_ID'],
			'MESSAGE_TEXT' => $messageFields['MESSAGE']
		]);
		if (
			$resultTitle->isSuccess()
			&& ($outputTitle = $resultTitle->getData())
			&& !empty($outputTitle['SUMMARY'])
		)
		{
			self::renameChat($chat, $outputTitle['SUMMARY']);
		}
		elseif (!$resultTitle->isSuccess())
		{
			$error = $resultTitle->getErrors()[0];
			ImBot\Log::write(
				[
					'errorCode' => $error->getCode(),
					'errorMessage' => $error->getMessage(),
					'chatId' => $messageFields['CHAT_ID'],
					'messageId' => $messageFields['MESSAGE_ID'],
				],
				'AI TITLE ERROR:'
			);
		}
	}

	/**
	 * Rename copilot chat.
	 *
	 * @param Im\V2\Chat $chat
	 * @param string $title
	 * @return void
	 */
	private static function renameChat(Im\V2\Chat $chat, string $title): void
	{
		if (
			!empty($title)
			&& $chat instanceof Im\V2\Chat\CopilotChat
		)
		{
			//todo: Use v2 api for renaming
			//$chat->setTitle($title)->save();

			(new \CIMChat())->rename($chat->getChatId(), $title, false, false);
		}
	}

	//endregion

	//region Send message

	/**
	 * Sends message to client.
	 *
	 * @param int $chatId
	 * @param array|string $message
	 * @param bool $needToReplaceAiMentions
	 * @return void
	 */
	protected static function sendMessage(int $chatId, $message, bool $needToReplaceAiMentions = true): void
	{
		if (!is_array($message))
		{
			$message = ['MESSAGE' => $message];
		}

		$message['FROM_USER_ID'] = self::getBotId();
		$message['CHAT_ID'] = $chatId;
		$message['URL_PREVIEW'] = 'N';

		if (!empty($message['PARAMS']))
		{
			$message['PARAMS'] = [];
		}

		$chat = Chat::getInstance($chatId);

		$message['PARAMS'][Im\V2\Message\Params::COMPONENT_ID] = self::MESSAGE_COMPONENT_ID;
		$message['PARAMS'][Im\V2\Message\Params::COPILOT_ROLE] = (new Im\V2\Integration\AI\RoleManager())->getMainRole($chatId);
		if (!$chat->getRelationByUserId(self::getBotId()))
		{
			$message['SKIP_USER_CHECK'] = 'Y';
		}
		if ($needToReplaceAiMentions)
		{
			$message['MESSAGE'] = self::replaceAiMentions($message['MESSAGE'], $chatId);
		}
		$message['MESSAGE'] = self::convertToBbCode($message['MESSAGE']);
		$message['MESSAGE'] = self::convertToBbCode($message['MESSAGE']);
		if ($chat instanceof Chat\PrivateChat)
		{
			$message['PARAMS']['NOTIFY'] = 'N';
		}

		\CIMMessenger::add($message);
	}

	/**
	 * Sends message to client.
	 *
	 * @param int $chatId
	 * @param array|string $message
	 * @return void
	 */
	protected static function sendError(int $chatId, $message): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		if (!is_array($message))
		{
			$message = ['MESSAGE' => $message];
		}

		$message['FROM_USER_ID'] = self::getBotId();
		$message['CHAT_ID'] = $chatId;
		$message['URL_PREVIEW'] = 'N';

		if (!empty($message['PARAMS']))
		{
			$message['PARAMS'] = [];
		}

		$message['PARAMS'][Im\V2\Message\Params::COMPONENT_ID] = self::MESSAGE_COMPONENT_ID;
		$message['PARAMS'][Im\V2\Message\Params::COMPONENT_PARAMS] = [
			self::MESSAGE_PARAMS_ERROR => true
		];
		$message['PARAMS'][Im\V2\Message\Params::COPILOT_ROLE] = (new Im\V2\Integration\AI\RoleManager())->getMainRole($chatId);

		$chat = Chat::getInstance($chatId);
		if (!$chat->getRelationByUserId(self::getBotId()))
		{
			$message['SKIP_USER_CHECK'] = 'Y';
		}

		\CIMMessenger::add($message);
	}

	/**
	 * Sends typing event.
	 *
	 * @param int $chatId
	 * @return void
	 * @throws LoaderException
	 */
	protected static function sendTyping(int $chatId, ?bool $reasoning = false): void
	{
		$action = new Chat\InputAction\Action(Chat::getInstance($chatId), Chat\InputAction\Type::Writing);
		$action->setContextUser(self::getBotId());

		if ($reasoning)
		{
			$action->setDuration(600)->setStatusMessageCode('IMBOT_COPILOT_INPUT_ACTION_THINKING');
		}

		$action->notify();

		if (Loader::includeModule('pull'))
		{
			Pull\Event::send();
		}
	}

	public static function onChatStart($dialogId, $joinFields)
	{
		return false;
	}

	public static function onGetInputActionStatusMessages()
	{
		return new EventResult(
			EventResult::SUCCESS,
			[
				'IMBOT_COPILOT_INPUT_ACTION_THINKING' => Loc::getMessage('IMBOT_COPILOT_INPUT_ACTION_THINKING'),
			],
			self::MODULE_ID
		);
	}

	//endregion

	//region Options

	/**
	 * Returns current mode interaction with AI service: asynchronous requests or long pulling request.
	 * @return string
	 */
	public static function getMode(): string
	{
		static $mode;
		if ($mode === null)
		{
			$mode = Option::get(self::MODULE_ID, self::OPTION_MODE, self::MODE_LONG_PULLING);
			if (!in_array($mode, [self::MODE_LONG_PULLING, self::MODE_ASYNC_QUEUE]))
			{
				$mode = self::MODE_LONG_PULLING;
			}
		}

		return $mode;
	}

	protected static function getContextLimit(bool $byMention): int
	{
		if ($byMention)
		{
			return Option::get(self::MODULE_ID, self::OPTION_MENTION_CONTEXT_AMOUNT, self::EXTERNAL_MENTION_CONTEXT_AMOUNT);
		}

		return self::getContextAmount();
	}

	protected static function convertToBbCode(string $text): string
	{
		if (Loader::includeModule('ai') && class_exists(MarkdownToBBCodeTranslationService::class))
		{
			return ServiceLocator::getInstance()->get(MarkdownToBBCodeTranslationService::class)->convert($text);
		}

		return $text;
	}


	/**
	 * Returns amount messages for context.
	 * @return int
	 */
	public static function getContextAmount(): int
	{
		static $amount;
		if ($amount === null)
		{
			$amount = (int)Option::get(self::MODULE_ID, self::OPTION_CONTEXT_AMOUNT, self::CONTEXT_AMOUNT_DEFAULT);
			if ($amount == 0)
			{
				$amount = self::CONTEXT_AMOUNT_DEFAULT;
			}
		}

		return $amount;
	}

	public static function isMemoryContextEnabled(): bool
	{
		return Option::get(self::MODULE_ID, 'enableCopilotMemoryContext', 'N') === 'Y';
	}

	//endregion
}
