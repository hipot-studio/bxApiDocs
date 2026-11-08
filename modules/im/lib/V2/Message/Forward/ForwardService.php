<?php

namespace Bitrix\Im\V2\Message\Forward;

use Bitrix\Im\Message\Uuid;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Entity\File\ParamCollection;
use Bitrix\Im\V2\Integration\AI\RoleManager;
use Bitrix\Im\V2\Integration\AI\Transcription\TranscriptionCopyManager;
use Bitrix\Im\V2\Logger;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderService;
use Bitrix\Im\V2\Message\Contact\ChatTypePolicy;
use Bitrix\Im\V2\Message\Contact\ContactCardError;
use Bitrix\Im\V2\Message\Contact\ContactMessage;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Result;
use Bitrix\Imbot\Bot\CopilotChatBot;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;

class ForwardService
{
	use ContextCustomer;

	private const PARAMS_TO_COPY_WHITELIST = [
		Message\Params::ATTACH => Message\Params::ATTACH,
		Message\Params::URL_ID => Message\Params::URL_ID,
		Message\Params::FILE_ORDER => Message\Params::FILE_ORDER,
		Message\Params::IS_DELETED => Message\Params::IS_DELETED,
		Message\Params::URL_ONLY => Message\Params::URL_ONLY,
		Message\Params::LARGE_FONT => Message\Params::LARGE_FONT,
		Message\Params::FORWARD_CONTEXT_ID => Message\Params::FORWARD_CONTEXT_ID,
		Message\Params::FORWARD_ID => Message\Params::FORWARD_ID,
		Message\Params::FORWARD_USER_ID => Message\Params::FORWARD_USER_ID,
		Message\Params::FORWARD_CHAT_TITLE => Message\Params::FORWARD_CHAT_TITLE,
		Message\Params::REPLY_ID => Message\Params::REPLY_ID,
		Message\Params::STICKER_PARAMS => Message\Params::STICKER_PARAMS,
		Message\Params::BLOCK => Message\Params::BLOCK,
	];

	private Chat $toChat;
	private bool $sentFromUi;
	private bool $isSystemForward = false;

	public function __construct(Chat $toChat, bool $sentFromUi = false)
	{
		$this->toChat = $toChat;
		$this->sentFromUi = $sentFromUi;
	}

	/**
	 * A forward the server makes itself to carry the source of an operation, not one a user asked for.
	 * The mode says who started the forwarding and nothing about what the initiator may read: access
	 * to the source chat is checked for it exactly as for any other forward.
	 */
	public function markAsSystemForward(): self
	{
		$this->isSystemForward = true;

		return $this;
	}

	/**
	 * @param MessageCollection $forwardingMessages
	 * @return Result<MessageCollection>
	 */
	public function createMessages(MessageCollection $forwardingMessages): Result
	{
		$result = new ForwardResult();
		$contactValidationResult = $this->validateContactForwarding($forwardingMessages);
		if (!$contactValidationResult->isSuccess())
		{
			return $result->addErrors($contactValidationResult->getErrors());
		}

		$fileCopyMap = new FileCopyMap();
		$messageConfigs = [];
		$uuidMap = [];
		$uuidServices = $this->getExistingUuidServices($forwardingMessages);

		foreach ($forwardingMessages as $forwardingMessage)
		{
			$uuid = $forwardingMessage->getForwardUuid();
			$canForwardResult = $this->canForward($forwardingMessage);
			if (!$canForwardResult->isSuccess())
			{
				$this->addMessageErrors($result, $forwardingMessage, $canForwardResult->getErrors());

				continue;
			}

			$targetChatResult = $this->getTargetChat($this->getContext()->getUserId(), $uuid);
			if (!$targetChatResult->isSuccess())
			{
				$this->addMessageErrors($result, $forwardingMessage, $targetChatResult->getErrors());

				continue;
			}

			$uuidService = $uuid === null ? null : ($uuidServices[$uuid] ?? null);
			$duplicateResult = $this->findDuplicateMessageId($uuid, $uuidService);
			if (!$duplicateResult->isSuccess())
			{
				$this->addMessageErrors($result, $forwardingMessage, $duplicateResult->getErrors());

				continue;
			}
			if ($duplicateResult->hasResult())
			{
				$result->markMessageAsSuccessful($forwardingMessage);
				$uuidMap[$uuid] = $duplicateResult->getResult();

				continue;
			}

			$messageConfigResult = $this->buildForwardMessageConfig($forwardingMessage, $fileCopyMap);
			if (!$messageConfigResult->isSuccess())
			{
				$this->addMessageErrors($result, $forwardingMessage, $messageConfigResult->getErrors());

				continue;
			}

			$messageConfigs[] = [
				'config' => $messageConfigResult->getResult(),
				'sourceMessage' => $forwardingMessage,
				'targetChat' => $targetChatResult->getResult(),
				'uuidReservation' => $uuidService,
			];
		}

		foreach ($messageConfigs as $messageConfig)
		{
			$forwardMessageResult = $this->createForwardMessage(
				$messageConfig['config'],
				$messageConfig['targetChat'],
				$fileCopyMap,
				$messageConfig['uuidReservation'],
			);
			if ($forwardMessageResult->hasResult())
			{
				$result->markMessageAsSuccessful($messageConfig['sourceMessage']);
				$messageMap = $forwardMessageResult->getResult();
				if (!empty($messageMap['uuid']))
				{
					$uuidMap[$messageMap['uuid']] = $messageMap['id'];
				}
			}

			$this->addMessageErrors($result, $messageConfig['sourceMessage'], $forwardMessageResult->getErrors());
		}

		ParamCollection::copyParams($fileCopyMap);
		(new TranscriptionCopyManager($fileCopyMap))->copy();

		return $result->setResult($uuidMap);
	}

	public static function getChatTypeByContextId(string $contextId): string
	{
		$dialogId = Chat::getDialogIdByContextId($contextId);

		if (!str_starts_with($dialogId, 'chat'))
		{
			return \Bitrix\Im\Chat::getType(['TYPE' => Chat::IM_TYPE_PRIVATE]);
		}

		$chatId = mb_substr($dialogId, 4);

		return Chat::getInstance($chatId)->getExtendedType();
	}

	private function createForwardMessage(
		array $messageConfig,
		Chat $chat,
		FileCopyMap $fileCopyMap,
		?Uuid $uuidReservation = null,
	): Result
	{
		$result = new Result();
		$uuid = $messageConfig['UUID'] ?: null;
		$authorId = (int)$messageConfig['AUTHOR_ID'];

		$message = (new Message())
			->setAuthorId($authorId)
			->setChatId((int)$messageConfig['CHAT_ID'])
			->setContextUser($authorId)
			->setParams($messageConfig['PARAMS'])
			->setUuid($messageConfig['UUID'])
			->markSentFromUi((bool)($messageConfig['SENT_FROM_UI'] ?? false))
		;
		if ($messageConfig['MESSAGE'] !== null)
		{
			$message->setMessage($messageConfig['MESSAGE']);
		}

		FileCollection::addDiskFilesToPreload($messageConfig['FILE_MODELS']);

		if ($chat->getEntityType() === Chat::ENTITY_TYPE_VIDEOCONF || !$chat->canDo(Action::Send))
		{
			$this->takeFileCopiesBack($messageConfig['FILE_COPIES'], $fileCopyMap);

			return $result->addError(
				ForwardError::fromError(new ChatError(ChatError::ACCESS_DENIED), $uuid),
			);
		}

		// The expired reservation of this uuid is already taken over by the early check, so the sending
		// service must reuse it instead of failing its own duplicate check on the refreshed record.
		$sendingConfig = (new SendingConfig())
			->disableGenerateUrlPreview()
			->setMessageUuidReservation($uuidReservation)
		;

		$sendResult = $chat->sendMessage($message, $sendingConfig);
		if (!$sendResult->isSuccess())
		{
			$this->takeFileCopiesBack($messageConfig['FILE_COPIES'], $fileCopyMap);
			$this->addForwardErrors($result, $sendResult->getErrors(), $uuid);

			return $result;
		}

		$messageId = $sendResult->getMessageId();
		if ($messageId === null)
		{
			$this->takeFileCopiesBack($messageConfig['FILE_COPIES'], $fileCopyMap);

			return $result->addError(
				ForwardError::fromError(
					new Message\MessageError(Message\MessageError::SENDING_FAILED),
					$uuid,
				),
			);
		}
		if ($message->getMessageId() === null)
		{
			// The atomic UUID check in sendMessage found a message created after the early check.
			$this->takeFileCopiesBack($messageConfig['FILE_COPIES'], $fileCopyMap);
		}

		return $result->setResult([
			'uuid' => $uuid,
			'id' => $messageId,
		]);
	}

	/**
	 * @return Result<Chat>
	 */
	private function getTargetChat(int $authorId, ?string $uuid): Result
	{
		$result = new Result();
		$chat = $this->toChat->withContextUser($authorId);

		if ($chat instanceof Chat\NullChat)
		{
			return $result->addError(
				ForwardError::fromError(new ChatError(ChatError::NOT_FOUND), $uuid),
			);
		}

		if ($chat->getEntityType() === Chat::ENTITY_TYPE_VIDEOCONF || !$chat->canDo(Action::Send))
		{
			return $result->addError(
				ForwardError::fromError(new ChatError(ChatError::ACCESS_DENIED), $uuid),
			);
		}

		return $result->setResult($chat);
	}

	/**
	 * @return Result<int>
	 */
	private function findDuplicateMessageId(?string $uuid, ?Uuid $uuidService): Result
	{
		$result = new Result();
		if ($uuid === null || !Uuid::validate($uuid) || $uuidService === null)
		{
			return $result;
		}

		$messageId = $uuidService->getMessageId();
		if ($messageId !== null)
		{
			return $result->setResult($messageId);
		}

		if (!$uuidService->updateIfExpired())
		{
			return $result->addError(new Message\MessageError(Message\MessageError::MESSAGE_DUPLICATED_BY_UUID));
		}

		return $result;
	}

	/**
	 * @return array<string, Uuid>
	 */
	private function getExistingUuidServices(MessageCollection $messages): array
	{
		$uuids = [];
		foreach ($messages as $message)
		{
			$uuid = $message->getForwardUuid();
			if ($uuid !== null)
			{
				$uuids[] = $uuid;
			}
		}

		return Uuid::getExistingByUuids($uuids);
	}

	/**
	 * @return Result<array>
	 */
	private function buildForwardMessageConfig(Message $forwardingMessage, FileCopyMap $fileCopyMap): Result
	{
		$result = new Result();

		$paramsResult = $this->buildForwardParams($forwardingMessage, $fileCopyMap);
		if (!$paramsResult->isSuccess())
		{
			return $result->addErrors($paramsResult->getErrors());
		}

		$params = $paramsResult->getResult();

		$messageConfig = [
			'MESSAGE' => $forwardingMessage->getMessage() !== '' ? $forwardingMessage->getMessage() : null,
			'PARAMS' => $params['PARAMS'] ?? [],
			'CHAT_ID' => $this->toChat->getChatId(),
			'AUTHOR_ID' => $this->getContext()->getUserId(),
			'UUID' => $forwardingMessage->getForwardUuid() ?? '',
			'FILE_MODELS' => $params['FILE_MODELS'] ?? [],
			'FILE_COPIES' => $params['FILE_COPIES'] ?? [],
		];

		if ($this->sentFromUi)
		{
			$messageConfig['SENT_FROM_UI'] = true;
		}

		return $result->setResult($messageConfig);
	}

	private function buildForwardParams(Message $forwardingMessage, FileCopyMap $fileCopyMap): Result
	{
		$result = new Result();

		$newParams = $this->getForwardingMessageParams($forwardingMessage);

		if ($this->isOriginalMessage($newParams))
		{
			$userId = $forwardingMessage->isSystem() ? 0 : $forwardingMessage->getAuthorId();
			$newParams[Message\Params::FORWARD_ID] = $forwardingMessage->getId();
			$newParams[Message\Params::FORWARD_CONTEXT_ID] = $forwardingMessage->getContextId();
			$newParams[Message\Params::FORWARD_USER_ID] = $userId;
			if ($forwardingMessage->getChat() instanceof Chat\OpenChannelChat)
			{
				$newParams[Message\Params::FORWARD_CHAT_TITLE] = $forwardingMessage->getChat()->getDisplayedTitle();
			}
		}

		$diskFiles = [];
		$copies = [];
		// the order of the copies is retold outside this block, so the map outlives it
		$copiedFileIds = [];

		if ($this->hasFilesToCopy($forwardingMessage))
		{
			$files = $forwardingMessage->getFiles()->getUnique();
			$attachmentIds = (array)$forwardingMessage->getParams()->get(Message\Params::FILE_ID)->getValue();
			if (array_diff($attachmentIds, $files->getIds()))
			{
				return $result->addError(new FileError(FileError::NOT_FOUND));
			}

			$newFileIds = [];
			foreach ($files as $file)
			{
				$copyResult = $file->getCopyToChatResult($this->toChat);
				if (!$copyResult->isSuccess())
				{
					$this->takeFileCopiesBack($copies, $fileCopyMap);

					return $result->addErrors($copyResult->getErrors());
				}

				$copy = $copyResult->getResult();
				$newFileIds[] = $copy->getId();
				$copiedFileIds[$file->getId()] = $copy->getId();
				$diskFiles[] = $copy->getDiskFile();
				$copies[] = $copy;
				$fileCopyMap->addFileIdMapping($copy, $file);
			}

			$fileIdsResult = $this->setFileIds($forwardingMessage, $newFileIds, $newParams, $copiedFileIds);
			if (!$fileIdsResult->isSuccess())
			{
				$this->takeFileCopiesBack($copies, $fileCopyMap);

				return $result->addErrors($fileIdsResult->getErrors());
			}

			$newParams = $fileIdsResult->getResult();
		}

		$newParams = self::setFileOrderOfCopies($newParams, $copiedFileIds);

		if (Loader::includeModule('imbot') && $forwardingMessage->getAuthorId() === CopilotChatBot::getBotId())
		{
			if ($forwardingMessage->getParams()->isSet(Message\Params::COPILOT_ROLE))
			{
				$copilotRole = $forwardingMessage->getParams()->get(Message\Params::COPILOT_ROLE)->getValue();
			}

			$newParams[Message\Params::COPILOT_ROLE] = $copilotRole ?? RoleManager::getDefaultRoleCode();
		}

		return $result->setResult([
			'PARAMS' => $newParams,
			'FILE_MODELS' => $diskFiles,
			'FILE_COPIES' => $copies,
		]);
	}

	/**
	 * @param FileItem[] $copies
	 */
	private function takeFileCopiesBack(array $copies, FileCopyMap $fileCopyMap): void
	{
		$deletedBy = $this->toChat->getContext()->getUserId();

		foreach ($copies as $copy)
		{
			$fileCopyMap->removeFileIdMapping($copy);

			$diskFile = $copy->getDiskFile();
			if ($diskFile !== null && !$diskFile->delete($deletedBy))
			{
				$logger = new Logger(
					"forward_file_copy_rollback_failed chatId={$this->toChat->getChatId()} fileId={$copy->getId()}"
				);
				$logger->logErrors(new ErrorCollection($diskFile->getErrors()));
			}
		}
	}

	private function hasFilesToCopy(Message $forwardingMessage): bool
	{
		return !empty($forwardingMessage->getFileIds());
	}

	/**
	 * The order taken from the original names its files, so it is retold in the ids of the copies: left as it was,
	 * it would be dropped whole by the reading rules and the copy would silently lose the sequence.
	 *
	 * @param array<int, int> $copiedFileIds original file id => id of its copy in the target chat
	 */
	private static function setFileOrderOfCopies(array $newParams, array $copiedFileIds): array
	{
		$fileOrder = $newParams[Message\Params::FILE_ORDER] ?? [];
		unset($newParams[Message\Params::FILE_ORDER]);

		if (empty($fileOrder) || !isset($newParams[Message\Params::FILE_ID]))
		{
			return $newParams;
		}

		$copyOrder = [];
		foreach ($fileOrder as $fileId)
		{
			if (isset($copiedFileIds[$fileId]))
			{
				$copyOrder[] = $copiedFileIds[$fileId];
			}
		}

		if (!empty($copyOrder))
		{
			$newParams[Message\Params::FILE_ORDER] = $copyOrder;
		}

		return $newParams;
	}

	/**
	 * @param array<int, int> $copiedFileIds original file id => id of its copy in the target chat
	 * @return Result<array>
	 */
	private function setFileIds(
		Message $forwardingMessage,
		array $newFileIds,
		array $newParams,
		array $copiedFileIds
	): Result
	{
		$result = new Result();
		$builder = $forwardingMessage->getBlocksBuilder();

		if ($builder !== null)
		{
			$builderService = ServiceLocator::getInstance()->get(BuilderService::class);
			$builderResult = $builderService->updateFileIds(
				$builder,
				$this->toChat,
				$copiedFileIds,
				$this->getMissingFileIds($forwardingMessage)
			);
			// a message that silently lost a copy of an available file is not the message that was forwarded
			if (!$builderResult->isSuccess())
			{
				return $result->addErrors($builderResult->getErrors());
			}

			$updatedBuilder = $builderResult->getBlocksBuilder();
			if ($updatedBuilder === null)
			{
				// nothing is left to hand the files to, so the copy carries its text and attachments alone
				unset($newParams[Message\Params::BLOCK]);
			}
			else
			{
				$newParams[Message\Params::BLOCK] = $updatedBuilder;
			}

			$attachmentIds = $this->getAttachmentCopyIds($forwardingMessage, $copiedFileIds);
			if (!empty($attachmentIds))
			{
				$newParams[Message\Params::FILE_ID] = $attachmentIds;
			}

			return $result->setResult($newParams);
		}

		$newParams[Message\Params::FILE_ID] = $newFileIds;

		return $result->setResult($newParams);
	}

	/**
	 * A file deleted from the disk after the message was sent keeps its id in the block, while the
	 * message does not resolve it any more: there is nothing to make a copy from. A file that is
	 * still there but did not get its copy is not one of those - that is a failure of the copying,
	 * and the blocks layer tells the two apart by this very list.
	 *
	 * @return int[]
	 */
	private function getMissingFileIds(Message $forwardingMessage): array
	{
		$availableFileIds = $forwardingMessage->getFiles()->getIds();
		$missingFileIds = [];

		foreach ($forwardingMessage->getFileIds() as $fileId)
		{
			$fileId = (int)$fileId;
			if (!isset($availableFileIds[$fileId]))
			{
				$missingFileIds[$fileId] = $fileId;
			}
		}

		return array_values($missingFileIds);
	}

	/**
	 * Files of the blocks are handed to the blocks themselves, but a message with blocks is free to carry
	 * plain attachments as well: those are the files of its own FILE_ID, and the copy keeps exactly them.
	 * A file the source message holds both ways stays in both places, as it did before the forwarding.
	 *
	 * @param array<int, int> $copiedFileIds original file id => id of its copy in the target chat
	 * @return int[]
	 */
	private function getAttachmentCopyIds(Message $forwardingMessage, array $copiedFileIds): array
	{
		$params = $forwardingMessage->getParams();
		if (!$params->isSet(Message\Params::FILE_ID))
		{
			return [];
		}

		$copyIds = [];
		foreach ($params->get(Message\Params::FILE_ID)->getValue() as $fileId)
		{
			if (isset($copiedFileIds[$fileId]))
			{
				$copyIds[] = $copiedFileIds[$fileId];
			}
		}

		return $copyIds;
	}

	/**
	 * If this message is already forwarded, then the params are already available
	 * @param array $messageParams
	 * @return bool
	 */
	private function isOriginalMessage(array $messageParams): bool
	{
		return !isset(
			$messageParams[Message\Params::FORWARD_CONTEXT_ID],
			$messageParams[Message\Params::FORWARD_USER_ID]
		);
	}

	/**
	 * @param Message $message
	 * @return array
	 */
	private function getForwardingMessageParams(Message $message): array
	{
		$result = [];
		foreach ($message->getParams() as $param)
		{
			if (isset(self::PARAMS_TO_COPY_WHITELIST[$param->getName()]))
			{
				$value = $param->getValue();
				if (is_bool($value))
				{
					$value = $value ? 'Y' : 'N';
				}
				$result[$param->getName()] = $value;
			}
		}

		if (ContactMessage::is($message))
		{
			$result[Message\Params::COMPONENT_ID] = ContactMessage::COMPONENT_ID;
			if ($message->getParams()->isSet(Message\Params::COMPONENT_PARAMS))
			{
				$result[Message\Params::COMPONENT_PARAMS] = $message
					->getParams()
					->get(Message\Params::COMPONENT_PARAMS)
					->getValue()
				;
			}
		}

		return $result;
	}

	private function validateContactForwarding(MessageCollection $messages): Result
	{
		$result = new Result();
		$hasContactMessage = false;
		foreach ($messages as $message)
		{
			if ($this->canForward($message)->isSuccess() && ContactMessage::is($message))
			{
				$hasContactMessage = true;

				break;
			}
		}

		if (!$hasContactMessage)
		{
			return $result;
		}

		if (!Features::isContactCardAvailable())
		{
			return $result->addError(new ContactCardError(ContactCardError::FEATURE_DISABLED));
		}

		if (!ChatTypePolicy::isAllowed($this->toChat))
		{
			return $result->addError(new ContactCardError(ContactCardError::CHAT_TYPE_RESTRICTED));
		}

		return $result;
	}

	private function canForward(Message $message): Result
	{
		$chat = $message->getChat();
		$accessResult = $chat->checkAccess($this->getContext()->getUserId());
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}

		// the threads feature governs the forwarding a user is offered in the interface; a system
		// forward is offered to nobody, so a discussion is a source like any other for it
		if ($chat instanceof Chat\CommentChat && !$this->isSystemForward && !Features::isChatThreadsAvailable())
		{
			return (new Result())->addError(new Message\MessageError(Message\MessageError::ACCESS_DENIED));
		}

		return new Result();
	}

	/**
	 * @param \Bitrix\Main\Error[] $errors
	 */
	private function addForwardErrors(Result $result, array $errors, ?string $uuid): void
	{
		foreach ($errors as $error)
		{
			$result->addError(ForwardError::fromError($error, $uuid));
		}
	}

	/**
	 * @param \Bitrix\Main\Error[] $errors
	 */
	private function addMessageErrors(ForwardResult $result, Message $message, array $errors): void
	{
		foreach ($errors as $error)
		{
			$forwardError = $error instanceof ForwardError
				? $error
				: ForwardError::fromError($error, $message->getForwardUuid());
			$result->markMessageAsFailed($message, $forwardError);
			$result->addError($forwardError);
		}
	}
}
