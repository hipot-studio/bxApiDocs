<?php

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\Dialog;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\CommentChat;
use Bitrix\Im\V2\Controller\Filter\ActionUuidHandler;
use Bitrix\Im\V2\Controller\Filter\AuthorizationPrefilter;
use Bitrix\Im\V2\Controller\Filter\AutoJoinToChat;
use Bitrix\Im\V2\Controller\Filter\CheckEntityAccess;
use Bitrix\Im\V2\Controller\Filter\CheckThreadPostAccess;
use Bitrix\Im\V2\Controller\Filter\SameChatMessageFilter;
use Bitrix\Im\V2\Controller\Filter\UpdateStatus;
use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Link\PersonalPin\PersonalPinCollection;
use Bitrix\Im\V2\Link\Pin\PinCollection;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\MessageError;
use Bitrix\Im\V2\Message\MessageService;
use Bitrix\Im\V2\Message\Sticker\PackType;
use Bitrix\Im\V2\Message\Sticker\StickerError;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Reading\ReadResult;
use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\Im\V2\Rest\RestError;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\SharingLink\SharingLinkError;
use Bitrix\Im\V2\SharingLink\SharingLinkFactory;
use Bitrix\Im\V2\SharingLink\SharingLink;
use Bitrix\Im\V2\TariffLimit\DateFilterable;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\ActionFilter\FilterType;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Type\ParameterDictionary;

abstract class BaseController extends Controller
{
	protected const MAX_LIMIT = 200;
	protected const DEFAULT_LIMIT = 50;
	protected const MAX_MESSAGES_COUNT = 100;

	private bool $threadPostAccessRequired = false;

	public function getAutoWiredParameters()
	{
		return [
			new ExactParameter(
				Chat::class,
				'chat',
				function($className, string $dialogId) {
					$chatId = Dialog::getChatId($dialogId);

					return Chat::getInstance((int)$chatId);
				}
			),
			new ExactParameter(
				Chat::class,
				'chat',
				function($className, int $chatId) {
					return Chat::getInstance($chatId);
				}
			),
			new ExactParameter(
				Message::class,
				'message',
				function ($className, int $messageId) {
					return new Message($messageId);
				}
			),
			new ExactParameter(
				MessageCollection::class,
				'messages',
				function ($className, array $messageIds) {
					return $this->getMessagesByIds($messageIds);
				}
			),
			new ExactParameter(
				Message::class,
				'message',
				function ($className, int $commentChatId) {
					return new Message(\Bitrix\Im\V2\Chat::getInstance($commentChatId)->getParentMessageId());
				}
			),
			new ExactParameter(
				\Bitrix\Im\V2\Chat::class,
				'chat',
				function($className, int $postId, string $createIfNotExists = 'N') {
					return $this->getChatByPostId($postId, $createIfNotExists === 'Y');
				}
			),
			new ExactParameter(
				PackType::class,
				'packType',
				function($className, string $packType) {
					return $this->getPackType($packType);
				}
			),
			new ExactParameter(
				SharingLink::class,
				'sharingLink',
				function ($className, string $code) {
					$sharingLink = SharingLinkFactory::getInstance()->getLinkByCode($code);
					if (!isset($sharingLink))
					{
						$this->addError(new SharingLinkError(SharingLinkError::NOT_FOUND));

						return null;
					}

					return $sharingLink;
				}
			),
			new ExactParameter(
				\Bitrix\Im\V2\Folder\Folder::class,
				'folder',
				function ($className, int $folderId) {
					$folder = ServiceLocator::getInstance()->get(FolderProvider::class)->getById($folderId);
					if ($folder === null)
					{
						$this->addError(new Error(FolderError::FOLDER_NOT_FOUND));

						return null;
					}

					return $folder;
				}
			),
		];
	}

	protected function getDefaultPreFilters()
	{
		return array_merge(
			[
				new AuthorizationPrefilter(),
				new UpdateStatus(),
			],
			parent::getDefaultPreFilters(),
			[
				new CloseSession(true),
				new SameChatMessageFilter(),
				new CheckEntityAccess(),
				new ActionUuidHandler(),
				new AutoJoinToChat(),
			]
		);
	}

	protected function getLimit(int $limit): int
	{
		return $limit > 0 && $limit <= static::MAX_LIMIT ? $limit : static::DEFAULT_LIMIT;
	}

	protected function toRestFormat(RestConvertible ...$entities): array
	{
		return (new RestAdapter(...$entities))->toRestFormat();
	}

	/**
	 * Injects the per-user copilot `vote` state into an assembled REST response. Must be called
	 * only from synchronous per-user read actions (message load/paging): `vote` is personalized
	 * for the requesting user and is intentionally absent from the shared popup-DTO layer to keep
	 * it out of broadcast pull/push payloads.
	 */
	protected function enrichCopilotVotes(array $rest): array
	{
		$userId = (int)($this->getCurrentUser()?->getId() ?? 0);

		return (new \Bitrix\Im\V2\Message\Vote\CopilotVoteEnricher())->enrich($rest, $userId);
	}

	/**
	 * Injects the per-user copilot MCP selection into an assembled REST response. Must be called
	 * only from the synchronous per-user chat load: the selection is stored per user and per chat,
	 * and the lookup is a per-chat one, so it belongs neither in the shared popup DTO nor on a path
	 * that assembles a list of chats.
	 */
	protected function enrichCopilotMcpSelection(array $rest): array
	{
		$userId = (int)($this->getCurrentUser()?->getId() ?? 0);

		return (new \Bitrix\Im\V2\Chat\Copilot\McpSelectionEnricher())->enrich($rest, $userId);
	}

	protected function load(\Bitrix\Im\V2\Chat $chat, int $messageLimit, int $pinLimit, bool $ignoreMark = false, ?Message $targetMessage = null): array
	{
		$messageLimit = $this->getLimit($messageLimit);
		$pinLimit = $this->getLimit($pinLimit);
		$messageService = new MessageService($targetMessage ?? $chat->getLoadContextMessage($ignoreMark));
		$messages = $messageService->getMessageContext($messageLimit, Message::REST_FIELDS)->getResult();
		$pinFilter = ['CHAT_ID' => $chat->getChatId(), 'START_ID' => $chat->getStartId() ?: null];
		$pins = PinCollection::find($pinFilter, ['ID' => 'DESC'], $pinLimit);
		$personalPins = PersonalPinCollection::findForOwner(
			(int)($this->getCurrentUser()?->getId() ?? 0),
			$pinFilter,
			['ID' => 'DESC'],
			$pinLimit
		);

		$restAdapter = new RestAdapter($chat, $messages, $pins, $personalPins);

		$rest = $restAdapter->toRestFormat();

		// Both blocks are capped by the controller limit, so the client is told when it has to read
		// the rest of a block through paging before numbering or searching over it. The flag counts
		// the records of the answer and not the rows read: the adapter narrows a block by the tariff
		// limit on its way out, and a flag raised over records the client never got would send it
		// paging for records that never come.
		$rest['pinsHasNextPage'] = count($rest[PinCollection::getRestEntityName()]) >= $pinLimit;
		$rest['personalPinsHasNextPage'] = count($rest[PersonalPinCollection::getRestEntityName()]) >= $pinLimit;

		$rest = $messageService->fillContextPaginationData($rest, $messages, $messageLimit);

		$rest = $this->enrichCopilotVotes($rest);

		return $this->enrichCopilotMcpSelection($rest);
	}

	protected function toRestFormatWithPaginationData(array $entities, int $needCount, int $realCount): array
	{
		$rest = (new RestAdapter(...$entities))->toRestFormat();

		// $realCount is the number of rows the caller read, and a tariff-filterable block gets narrowed
		// by the adapter on its way out. The flag has to describe the answer: raised over records the
		// client never got it would send it paging for records that never come.
		foreach ($entities as $entity)
		{
			if ($entity instanceof DateFilterable)
			{
				$realCount = min($realCount, count($rest[$entity::getRestEntityName()]));
			}
		}

		$rest['hasNextPage'] = $realCount >= $needCount;

		return $rest;
	}

	//todo: think about recursion in method.
	protected function checkWhiteList(array $fields, array $whiteList): array
	{
		$filteredFields = [];

		foreach ($whiteList as $allowedField)
		{
			if (isset($fields[$allowedField]))
			{
				$filteredFields[$allowedField] = $fields[$allowedField];
			}
		}

		return $filteredFields;
	}

	/**
	 * ALG-01: entering a discussion that already exists depends neither on the portal flag nor on the
	 * switch of the chat, so only the branch that opens a new one is guarded. The steps keep the order
	 * the whole mechanics keeps: the visibility of the post first, the structure of the parent after
	 * it, the rights last - a refusal must not tell the type of a chat the user cannot see.
	 */
	protected function getChatByPostId(int $postId, bool $createIfNotExists): ?Chat
	{
		$message = new Message($postId);

		if (!$message->checkAccess()->isSuccess())
		{
			$this->addError(new MessageError(MessageError::ACCESS_DENIED));

			return null;
		}

		$result = CommentChat::get($message, false);

		if (!$result->isSuccess() && $createIfNotExists)
		{
			$result = $this->createChatByPost($message);
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getResult();
	}

	/**
	 * @return Result<CommentChat|null>
	 */
	private function createChatByPost(Message $message): Result
	{
		$parentChat = $message->getChat();
		$accessResult = $parentChat->checkThreadCreateAccess();

		// nothing is created for a request that is going to be refused anyway
		if ($accessResult->isSuccess() && $this->threadPostAccessRequired)
		{
			$accessResult = $parentChat->checkThreadPostAccess();
		}

		return $accessResult->isSuccess() ? CommentChat::create($message) : $accessResult;
	}

	/**
	 * Creating a discussion is a state change of the parent chat: it publishes chatCreate to every
	 * member of the parent and an analytics event of its own. The arguments of an action are bound
	 * lazily, by the first prefilter that reads them, so on a path that writes into the discussion that
	 * state change used to happen ahead of every prefilter - including the one naming the very right
	 * the write needs - and a refused message left an empty discussion behind and told everybody about
	 * it. The right is established before anything is created now.
	 *
	 * Which actions those are is read off the prefilter the action itself declares and is not a list of
	 * names: the right that decides the refusal and the right gating the creation can then never name
	 * two different sets. Read here because the action is known before its arguments are bound, and
	 * because a controller has no other way of telling which of its actions is running.
	 */
	protected function processBeforeAction(Action $action)
	{
		$this->threadPostAccessRequired = $this->declaresThreadPostAccessCheck($action);

		return parent::processBeforeAction($action);
	}

	private function declaresThreadPostAccessCheck(Action $action): bool
	{
		$config = $this->getActionConfig($action->getName()) ?? [];
		$prefilters = array_merge(
			$config[FilterType::Prefilter->value] ?? [],
			$config[FilterType::EnablePrefilter->value] ?? [],
		);

		foreach ($prefilters as $prefilter)
		{
			if ($prefilter instanceof CheckThreadPostAccess)
			{
				return true;
			}
		}

		return false;
	}

	protected function getMessagesByIds(array $ids): ?MessageCollection
	{
		if (count($ids) > static::MAX_MESSAGES_COUNT)
		{
			$this->addError(new MessageError(MessageError::TOO_MANY_MESSAGES));

			return null;
		}
		$ids = array_map('intval', $ids);

		$messageCollection = new MessageCollection($ids);

		if ($messageCollection->isEmpty())
		{
			$this->addError(new MessageError(MessageError::NOT_FOUND));

			return null;
		}

		return $messageCollection;
	}

	protected function convertCharToBool(string $char, bool $default = false): bool
	{
		if ($char === 'Y')
		{
			return true;
		}
		if ($char === 'N')
		{
			return false;
		}

		return $default;
	}

	protected function getRawValue(string $key)
	{
		return $this->prepareRawValue($this->request->getPostList(), $key)
			?? $this->prepareRawValue($this->request->getQueryList(), $key)
			?? null
		;
	}

	private function prepareRawValue(ParameterDictionary $list, string $key)
	{
		$rawData = $list->toArrayRaw();
		if (isset($rawData[$key]))
		{
			return $rawData[$key];
		}

		$data  = $list->toArray();

		return $data[$key] ?? null;
	}

	protected function prepareFields(array $fields, array $whiteList): array
	{
		$converter = new Converter(Converter::TO_SNAKE | Converter::TO_UPPER | Converter::KEYS);
		$fields = $converter->process($fields);

		return  $this->checkWhiteList($fields, $whiteList);
	}

	protected function getPackType(string $packType): ?PackType
	{
		$packType = PackType::tryFrom($packType);

		if ($packType === null)
		{
			$this->addError(new StickerError(StickerError::WRONG_PACK_TYPE));

			return null;
		}

		return $packType;
	}

	protected function formReadResult(\Bitrix\Im\V2\Chat $chat, ReadResult $readResult): ?array
	{
		if (!$readResult->isSuccess())
		{
			$this->addErrors($readResult->getErrors());

			return null;
		}

		return [
			'chatId' => $chat->getId(),
			'lastId' => $chat->getLastId(),
			'counter' => $readResult->getCounter(),
			'viewedMessages' => $readResult->getViewedMessages()?->getIds() ?? [],
		];
	}

	protected function getDateOrSetError(string $date): ?DateTime
	{
		if (!DateTime::isCorrect($date, \DateTimeInterface::RFC3339))
		{
			$this->addError(new RestError(RestError::WRONG_DATETIME_FORMAT));

			return null;
		}

		return new DateTime($date, \DateTimeInterface::RFC3339);
	}
}
