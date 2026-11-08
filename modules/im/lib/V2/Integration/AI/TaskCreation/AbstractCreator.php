<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\TaskCreation;

use Bitrix\AI\Context;
use Bitrix\AI\Engine;
use Bitrix\AI\Payload\Prompt;
use Bitrix\AI\Quality;
use Bitrix\AI\Tuning\Defaults;
use Bitrix\Im\V2\Chat\OpenLineChat;
use Bitrix\Im\V2\Chat\OpenLineLiveChat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserFactory;
use Bitrix\Im\V2\Integration\AI\CopilotError;
use Bitrix\Im\V2\Integration\AI\MentionService;
use Bitrix\Im\V2\Integration\AI\SimpleHistoryBuilder;
use Bitrix\Im\V2\Integration\AI\SimpleHistoryBuilder\Direction;
use Bitrix\Im\V2\Integration\AI\SimpleHistoryBuilder\Options;
use Bitrix\Im\V2\Integration\AI\SimpleHistoryBuilder\Window;
use Bitrix\Im\V2\Integration\AI\Transcription\LaunchType;
use Bitrix\Im\V2\Integration\AI\UserKeyConverter;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\TranscriptionHandler;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderService;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

abstract class AbstractCreator
{
	use ContextCustomer;

	/**
	 * Bounds of the context window as it looked when the user pressed the button. They travel
	 * through the transcription context, so that a query assembled after a deferred transcription
	 * sees the same neighbourhood and not the messages that arrived meanwhile.
	 */
	public const CONTEXT_FROM_ID_PARAM = 'contextFromId';
	public const CONTEXT_TO_ID_PARAM = 'contextToId';
	public const CONTEXT_START_ID_PARAM = 'contextStartId';

	/**
	 * Characters one query to the model may carry: the source text and the context window around
	 * it together. Not a limit of a transcribed file, which stays in the transcription flow.
	 */
	public const MAX_QUERY_CHARS = 20000;

	protected const MODULE_ID = 'im';
	/** Prompt of an automatic launch: a transcription nobody asked a task for. */
	protected const PROMPT_CODE = 'task_extraction_prompt';
	/**
	 * Prompt of a manual launch. The split runs by launch mode and not by creator class: a single
	 * voice or video note is served by TranscriptionCreator even when a person asked for the task
	 * (@see CreatorFactory::createByMessages()), so the class of the creator says nothing about
	 * who started the operation.
	 */
	protected const MANUAL_PROMPT_CODE = 'message_task_extraction_prompt';
	protected const DATE_FORMAT = 'Y/m/d H:i';
	protected const CONTEXT_DEPTH_OPTION = 'ai_task_context_depth';
	protected const DEFAULT_CONTEXT_DEPTH = 25;

	/**
	 * Ceiling of the context depth option, in messages per side (a window of 50). Above it the
	 * cost of the selection keeps growing and the result does not: whatever does not fit into the
	 * MAX_QUERY_CHARS budget of 20000 characters is dropped by fitIntoQueryBudget() anyway, so the
	 * extra pages are read, rendered and thrown away. The option has no admin interface and is set
	 * by operations, hence a ceiling in the code and not a validation message.
	 */
	protected const MAX_CONTEXT_DEPTH = 25;

	private const SINGLE_MESSAGE_LOCK_TIMEOUT = 3;

	protected Message $message;

	/**
	 * How many ids the request carried, not how many messages survived access and same-chat
	 * filtering. Zero means the creator was built outside a user request (queue callbacks).
	 */
	protected int $requestedMessagesCount = 0;

	/**
	 * Bounds of the context window taken when the button was pressed. Only a launch that outlived
	 * its own request carries them, @see self::extractContextBounds().
	 *
	 * @var array<string, int>
	 */
	protected array $contextBounds = [];

	abstract public function sendAiQuery(): Result;

	public function getMessage(): Message
	{
		return $this->message;
	}

	public function setRequestedMessagesCount(int $count): static
	{
		$this->requestedMessagesCount = $count;

		return $this;
	}

	/**
	 * @param array<string, int> $contextBounds @see self::extractContextBounds()
	 */
	public function setContextBounds(array $contextBounds): static
	{
		$this->contextBounds = $contextBounds;

		return $this;
	}

	public function createTask(
		array $data,
		string $sourceText,
		int $userId,
		LaunchType $launchType = LaunchType::Auto
	): void
	{
		if ($this->isEmptyMessage())
		{
			$this->reportLostSource($userId, $launchType);

			return;
		}

		Locator::getContext()->setUser($userId);
		$transcriptionHandler = ServiceLocator::getInstance()->get(TranscriptionHandler::class);
		$transcriptionHandler->handle($data, $this->message, $userId, $launchType);
	}

	protected function isEmptyMessage(): bool
	{
		return $this->message->getMessageId() === null || $this->message->getChatId() === null;
	}

	/**
	 * A source that lost its message or chat id can no longer become a task, and a manual launch owes
	 * its initiator a terminal verdict anyway. Every callback that reaches createTask() checks the
	 * source itself and answers before the call, so this is a safety net and not the normal path.
	 * An automatic launch keeps its current outcome: its indication is not part of this iteration.
	 */
	protected function reportLostSource(int $userId, LaunchType $launchType): void
	{
		if ($launchType !== LaunchType::Manual || $userId <= 0)
		{
			return;
		}

		(new AutoTaskStatus($this->message, $userId, Status::NotFound))->send();
	}

	protected function isTaskChat(): bool
	{
		return $this->message->getChat()->getEntityType() === 'TASKS_TASK';
	}

	protected function convertBoolToInt(bool $value): int
	{
		return $value ? 1 : 0;
	}

	protected function getCurrentTime(): string
	{
		$userTimeZone = User::getInstance($this->getContext()->getUserId())->getTimezone();
		$timeZoneOffset = \CTimeZone::getTimezoneOffset($userTimeZone);
		$dateTime = DateTime::createFromTimestamp((new DateTime())->getTimestamp() + $timeZoneOffset);

		return $dateTime->format(self::DATE_FORMAT);
	}

	protected function getChatUsers(bool $anonymizeUserFormat = true): string
	{
		$result = [];
		$userIds = $this->message->getChat()->getRelations()->getUserIds();
		$userKeyConverter = new UserKeyConverter((int)$this->message->getChatId());

		foreach ($userIds as $userId)
		{
			$result[] = $anonymizeUserFormat
				? $userKeyConverter->getAnonymizedUserKey($userId)
				: $userKeyConverter->getUserKey($userId)
			;
		}

		return implode(', ', $result);
	}

	/**
	 * One line of the source as the model reads it: the key of its author, then the text with every
	 * mention it carries resolved into the key of the participant it names. Both keys are built the
	 * way the context window and chat_users build them, so one participant never reaches the model
	 * under two names - as a key in the participant list and as BB code inside the assignment, which
	 * no rule of the prompt could match against that list. A message whose author is unknown keeps
	 * its text unprefixed: a key no entry of the participant list carries would name somebody who is
	 * not in the chat. Its mentions are resolved all the same - they name participants of their own.
	 *
	 * Mentions are the only markup rewritten here. The window rewrites exactly those and leaves the
	 * rest of the BB codes ([URL], [SIZE], [COLOR]) as they are, @see
	 * SimpleHistoryBuilder::renderWindowBlock(), so cleaning them out of the source would split the
	 * two languages apart again, only from the other side.
	 */
	protected function buildSourceLine(Message $message, string $text): string
	{
		$chatId = (int)$this->message->getChatId();
		// null means the replacement itself failed, and a source with raw mentions still beats none
		$text = MentionService::getInstance()->replaceBbMentions($text, $chatId) ?? $text;
		$authorId = $message->getAuthorId();

		if ($text === '' || $authorId <= 0)
		{
			return $text;
		}

		$userKeyConverter = new UserKeyConverter($chatId);

		return "{$userKeyConverter->getAnonymizedUserKey($authorId)}: {$text}";
	}

	/**
	 * The context window always names its authors by the anonymized key: its blocks go through
	 * MentionService::replaceBbMentions(), which has no engine branch. So chat_users has to use the
	 * same form whenever the window reaches the model - otherwise one participant reaches it under
	 * two names and attribution breaks. A query the window never got into keeps the format the engine
	 * asks for: an automatic launch, a selection of several messages and a source text that took the
	 * whole character budget all send the participant list alone.
	 */
	protected function requiresAnonymizedUserFormat(Engine $engine, bool $queryCarriesWindow): bool
	{
		return $queryCarriesWindow || $engine->requiresPersonalDataObfuscation();
	}

	/**
	 * Takes the dedup slot of a single-message task creation. The plain single-message branch and
	 * the transcription branch compete for the same message, so both go through this method: a
	 * different key or lock name would make the two branches blind to each other.
	 *
	 * The caller owns the slot until the queue callback releases it, and must release it itself
	 * when the query never reaches the queue.
	 *
	 * @return bool false when a live attempt for the same message already holds the slot
	 */
	protected function reserveSingleMessageSlot(int $messageId): bool
	{
		$connection = Application::getConnection();
		$lockName = "im_auto_task_creation_single_message_{$messageId}";

		if (!$connection->lock($lockName, self::SINGLE_MESSAGE_LOCK_TIMEOUT))
		{
			// a concurrent attempt for the same message holds the lock - let it finish
			return false;
		}

		try
		{
			$queueManager = AutoTaskQueueManager::getInstance();

			if ($queueManager->inQueue([$messageId]))
			{
				return false;
			}

			$queueManager->add([$messageId]);
		}
		finally
		{
			$connection->unlock($lockName);
		}

		return true;
	}

	/**
	 * The answer a launch gets when the dedup slot of its source is already taken.
	 *
	 * The slot is keyed by the messages alone (@see AutoTaskType::buildEntityId()), so every
	 * initiator of the chat shares it. The launch that did not get it owns no operation: no queue
	 * callback of its own will arrive, and neither will a terminal pull status, because those are
	 * addressed to the initiator of the operation that owns the slot. An empty successful Result
	 * would therefore answer {result: true} and then stay silent - until the record expires, which
	 * for an abandoned operation is the whole TTL. A manual launch has to end with a task or with
	 * an error, so a taken slot is reported as a refusal.
	 *
	 * The refusal carries a code of its own: the source is fine here and the launch is worth
	 * repeating once the operation holding the slot is over, while AI_TASK_SOURCE_NOT_SUPPORTED
	 * stands for a source no launch can ever turn into a task.
	 */
	protected function refuseTakenSlot(): Result
	{
		return (new Result())->addError(new CreationError(CreationError::AI_TASK_CREATION_IN_PROGRESS));
	}

	protected function getEngine(Context $context): ?Engine
	{
		$availableEngines = Defaults::getProviderSelectFieldParams(
			'text',
			new Quality(Quality::QUALITIES['chat_task_creation_from_transcription'])
		);

		return Engine::getByCode($availableEngines['default'] ?? '', $context);
	}

	/**
	 * @param LaunchType $launchType a manual launch is the only one the context window is built for.
	 */
	protected function dispatchTaskExtractionQuery(
		Context $context,
		string $sourceText,
		int $userId,
		LaunchType $launchType = LaunchType::Manual
	): Result
	{
		$result = new Result();

		$engine = $this->getEngine($context);
		if ($engine === null)
		{
			return $result->addError(new CopilotError(CopilotError::ENGINE_NOT_FOUND));
		}

		// everything below names participants: the window blocks resolve an author and every
		// mention, and chat_users walks the whole relation list - all of it through the per-user
		// cache of UserFactory. One batch warm-up fills the very same keys those reads look up.
		UserFactory::getInstance()->preloadUsers($this->message->getChat()->getRelations()->getUserIds());

		// a source text that fills the budget on its own leaves the window no space at all, and
		// fitIntoQueryBudget() drops it whole in that case - so it is not collected to begin with
		[$blocksBefore, $blocksAfter] = mb_strlen($sourceText) >= self::MAX_QUERY_CHARS
			? [[], []]
			: $this->collectContextBlocks($launchType, $userId)
		;
		[$sourceText, $contextText, $contextAfterText] = $this->fitIntoQueryBudget(
			$sourceText,
			$blocksBefore,
			$blocksAfter,
		);
		// the window is the only part of the query that names people by the anonymized key, and the
		// budget above may drop it whole, so the form of chat_users follows the text that got through
		$windowReachedTheModel = $contextText !== '' || $contextAfterText !== '';
		$anonymizeUserFormat = $this->requiresAnonymizedUserFormat($engine, $windowReachedTheModel);

		$engine
			->setPayload((new Prompt($this->getPromptCode($launchType)))->setMarkers([
				'transcript' => $sourceText,
				'chat_context' => $contextText,
				'chat_context_after' => $contextAfterText,
				'chat_users' => $this->getChatUsers($anonymizeUserFormat),
				'date_time' => $this->getCurrentTime(),
				'is_task_chat' => $this->convertBoolToInt($this->isTaskChat()),
				'anonymize_user_format' => $this->convertBoolToInt($anonymizeUserFormat),
			]))
			->setResponseJsonMode(true)
			->onError(static function (Error $processingError) use (&$result) {
				$result->addError($processingError);
			})
			->completionsInQueue()
		;

		if (!$result->isSuccess())
		{
			return $result;
		}

		(new AutoTaskStatus($this->message, $userId, Status::Search))->send();

		return $result;
	}

	protected function getPromptCode(LaunchType $launchType): string
	{
		return $launchType === LaunchType::Manual ? self::MANUAL_PROMPT_CODE : self::PROMPT_CODE;
	}

	/**
	 * Fits the query into MAX_QUERY_CHARS. The source text is what the task is built from, so it
	 * is cut only when it exhausts the budget alone; the auxiliary window gives up its space
	 * first and loses the blocks furthest from the source. The budget never cancels the operation.
	 *
	 * The window is filled outwards from the source, taking one block from a side at a time, so
	 * what survives is what stands nearest to it on either side. Each side stays an uninterrupted
	 * stretch touching the source - a block that does not fit closes its side instead of being
	 * skipped over, while the other side keeps collecting. At an equal distance the side before the
	 * source goes first: the line in front of the source is usually the one the source answers.
	 *
	 * @param string[] $blocksBefore blocks written before the source, chronological, closest last
	 * @param string[] $blocksAfter blocks written after the source, chronological, closest first
	 * @return array{0: string, 1: string, 2: string} source text and both sides of the window as
	 *         they go to the model
	 */
	protected function fitIntoQueryBudget(string $sourceText, array $blocksBefore, array $blocksAfter): array
	{
		if (mb_strlen($sourceText) >= self::MAX_QUERY_CHARS)
		{
			return [mb_substr($sourceText, 0, self::MAX_QUERY_CHARS), '', ''];
		}

		$rest = self::MAX_QUERY_CHARS - mb_strlen($sourceText);

		// both sides are walked outwards from the source, so the block nearest to it comes first
		$before = array_reverse($blocksBefore);
		$after = array_values($blocksAfter);
		$takenBefore = [];
		$takenAfter = [];

		$beforeIsOpen = true;
		$afterIsOpen = true;

		while ($beforeIsOpen || $afterIsOpen)
		{
			if ($beforeIsOpen)
			{
				$block = $before[count($takenBefore)] ?? null;
				$beforeIsOpen = $block !== null && mb_strlen($block) + 1 <= $rest;
				if ($beforeIsOpen)
				{
					$takenBefore[] = $block;
					$rest -= mb_strlen($block) + 1;
				}
			}

			if ($afterIsOpen)
			{
				$block = $after[count($takenAfter)] ?? null;
				$afterIsOpen = $block !== null && mb_strlen($block) + 1 <= $rest;
				if ($afterIsOpen)
				{
					$takenAfter[] = $block;
					$rest -= mb_strlen($block) + 1;
				}
			}
		}

		return [
			$sourceText,
			$this->joinWindowSide(array_reverse($takenBefore)),
			$this->joinWindowSide($takenAfter),
		];
	}

	/**
	 * One side of the window as the model reads it: a block per line, the last one closed by a line
	 * break too - which is the character every block is charged for on top of its own length.
	 *
	 * @param string[] $blocks chronological order
	 */
	private function joinWindowSide(array $blocks): string
	{
		return $blocks === [] ? '' : implode("\n", $blocks) . "\n";
	}

	/**
	 * Neighbourhood of the source message as standalone blocks, one list per side of it. The source
	 * message itself never gets in: it stays the only origin of the task and goes to the model in
	 * its own marker, between the two sides.
	 *
	 * @return array{0: string[], 1: string[]} blocks written before the source, chronological and
	 *         the closest to it last, then the ones written after it, the closest to it first. Both
	 *         are empty for every launch but a manual one over a single requested message.
	 */
	protected function collectContextBlocks(LaunchType $launchType, int $userId): array
	{
		$window = $this->buildContextWindow($launchType, $userId);

		return [$window?->getBlocksBefore() ?? [], $window?->getBlocksAfter() ?? []];
	}

	/**
	 * Bounds of the window built here and now, ready to be put into the transcription context.
	 * An empty array means there is no window to replay later.
	 *
	 * @return array<string, int>
	 */
	protected function buildContextBoundsSnapshot(int $userId): array
	{
		// only the three numbers below are read, so the blocks are not rendered at all
		$window = $this->buildContextWindow(LaunchType::Manual, $userId, withBlocks: false);
		if ($window === null)
		{
			return [];
		}

		return [
			self::CONTEXT_FROM_ID_PARAM => $window->getFromId(),
			self::CONTEXT_TO_ID_PARAM => $window->getToId(),
			self::CONTEXT_START_ID_PARAM => $window->getStartId(),
		];
	}

	/**
	 * Reads the window snapshot back from AI context parameters. A partial set is treated as no
	 * snapshot at all: replaying a window without one of its bounds would widen it silently.
	 *
	 * @param mixed $parameters AI context parameters as the queue gives them back
	 * @return array<string, int> empty when the launch carried no window
	 */
	public static function extractContextBounds(mixed $parameters): array
	{
		if (!is_array($parameters))
		{
			return [];
		}

		$bounds = [];

		foreach ([self::CONTEXT_FROM_ID_PARAM, self::CONTEXT_TO_ID_PARAM, self::CONTEXT_START_ID_PARAM] as $param)
		{
			if (!isset($parameters[$param]) || !is_numeric($parameters[$param]))
			{
				return [];
			}

			$bounds[$param] = (int)$parameters[$param];
		}

		return $bounds;
	}

	/**
	 * The window is auxiliary: an unavailable one is not a failure of the operation, the query just
	 * goes out without it. Built either from a snapshot of a deferred transcription, or - when the
	 * request itself carried exactly one id - from the chat as it looks right now.
	 */
	private function buildContextWindow(LaunchType $launchType, int $userId, bool $withBlocks = true): ?Window
	{
		if ($launchType !== LaunchType::Manual || $this->isEmptyMessage())
		{
			return null;
		}

		// the ids the request carried, not the messages that survived: a selection of two with one
		// of them deleted is still a multiple selection and gets no context
		if ($this->contextBounds === [] && $this->requestedMessagesCount !== 1)
		{
			return null;
		}

		$depth = $this->getContextDepth();
		if ($depth <= 0)
		{
			return null;
		}

		$options = new Options(
			direction: Direction::Around,
			boundaryUserId: $userId,
			withSystemMessages: false,
			withDeletedMessages: false,
			withAiTaskCards: false,
			withReplyTargets: false,
			withNeighbourTranscriptions: false,
			withBlocks: $withBlocks,
			fromId: $this->contextBounds[self::CONTEXT_FROM_ID_PARAM] ?? null,
			toId: $this->contextBounds[self::CONTEXT_TO_ID_PARAM] ?? null,
			startId: $this->contextBounds[self::CONTEXT_START_ID_PARAM] ?? null,
		);

		return (new SimpleHistoryBuilder($this->message, $depth))->buildWindow($options);
	}

	/**
	 * Messages per side of the window. Zero and below switch the window off, and the upper end is
	 * capped, @see self::MAX_CONTEXT_DEPTH. The same number is the page size of the selection, so a
	 * capped depth is also what keeps a single page of the scan narrow.
	 */
	private function getContextDepth(): int
	{
		$depth = (int)Option::get(self::MODULE_ID, self::CONTEXT_DEPTH_OPTION, self::DEFAULT_CONTEXT_DEPTH);

		return min($depth, self::MAX_CONTEXT_DEPTH);
	}

	protected function isDeniedChatForAutoTask(): bool
	{
		$chat = $this->message->getChat();

		return $chat instanceof OpenLineChat
			|| $chat instanceof OpenLineLiveChat
			|| $chat->getEntityType() === 'SUPPORT24_QUESTION' /** @see \Bitrix\ImBot\Bot\Support24::CHAT_ENTITY_TYPE */
			|| $chat->getEntityType() === 'NETWORK_DIALOG' /** @see \Bitrix\ImBot\Bot\Network::CHAT_ENTITY_TYPE */
		;
	}

	/**
	 * System messages, stickers and soft-deleted messages carry no meaningful task content.
	 */
	protected function isContentlessMessage(Message $message): bool
	{
		return $message->isSystem()
			|| $message->getParams()->isSet(Params::STICKER_PARAMS)
			|| $this->isDeletedMessage($message)
		;
	}

	/**
	 * A soft-deleted message keeps its DB row but its text is replaced by the "message deleted"
	 * placeholder and IS_DELETED is set, so it must never feed that placeholder into the AI query
	 * or into the created task.
	 */
	protected function isDeletedMessage(Message $message): bool
	{
		return $message->getParams()->get(Params::IS_DELETED)->getValue() === true;
	}

	protected static function extractSourceTextFrom(Message $message): string
	{
		$plain = trim((string)$message->getMessage());
		if ($plain !== '')
		{
			return $plain;
		}

		$builder = $message->getBlocksBuilder();
		if ($builder !== null)
		{
			$blockText = trim($builder->getPayloadText());
			if ($blockText !== '' && $blockText !== BuilderService::getPlaceholder())
			{
				return $blockText;
			}
		}

		return '';
	}

	/**
	 * Single owner of the default task title phrase: the lang file is mirrored for this class only,
	 * so every flow that needs the title (including the transcription handlers) must go through here.
	 */
	public static function getDefaultTaskTitle(): string
	{
		return Loc::getMessage('IM_AI_TASK_DEFAULT_TITLE') ?? '';
	}

	protected function hasMinimalTaskContent(): bool
	{
		return false;
	}

	protected function getMinimalTaskTitle(): string
	{
		return self::getDefaultTaskTitle();
	}

	protected function messageHasMinimalTaskContent(Message $message): bool
	{
		$params = $message->getParams();

		return $message->hasFiles()
			|| $params->isSet(Params::ATTACH)
			|| $params->isSet(Params::KEYBOARD)
			|| $params->isSet(Params::MENU)
		;
	}

	protected function createMinimalTask(int $userId, LaunchType $launchType = LaunchType::Auto): void
	{
		// a manual launch always ends with a task: without any content it gets the default title
		if ($launchType === LaunchType::Auto && !$this->hasMinimalTaskContent())
		{
			(new AutoTaskStatus($this->message, $userId, Status::NotFound))->send();

			return;
		}

		(new AutoTaskStatus($this->message, $userId, Status::Search))->send();

		$this->createTask(
			[
				'type' => 'task',
				'task_data' => [
					'title' => $this->getMinimalTaskTitle(),
				],
			],
			'',
			$userId,
			$launchType,
		);
	}
}
