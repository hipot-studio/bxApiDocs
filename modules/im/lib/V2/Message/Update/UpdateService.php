<?php

namespace Bitrix\Im\V2\Message\Update;

use Bitrix\Im\Bot;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Im\V2\Link\File\FileService;
use Bitrix\Im\V2\Link\Url\UrlService;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderService;
use Bitrix\Im\V2\Message\Contact\ContactMessage;
use Bitrix\Im\V2\Message\Delete\DeleteService;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class UpdateService
{
	use ContextCustomer;

	public const EVENT_AFTER_MESSAGE_UPDATE = 'OnAfterMessagesUpdate';

	private Message $message;
	private Message\Send\SendingConfig $sendingConfig;
	private ?array $chatLastMessage = null;
	private bool $byEvent = false;
	private bool $withCheckAccess = true;
	private bool $withTextDerivedUpdate = true;

	public function __construct(Message $message)
	{
		$this->message = $message;
		$this->sendingConfig = new Message\Send\SendingConfig();
	}

	public function setMessage(Message $message): self
	{
		$this->message = $message;

		return $this;
	}

	public function setUrlPreview(bool $urlPreview): self
	{
		if (!$urlPreview)
		{
			$this->sendingConfig->disableGenerateUrlPreview();
		}

		return $this;
	}

	public function setByEvent(bool $byEvent): self
	{
		$this->byEvent = $byEvent;

		return $this;
	}

	public function withoutCheckAccess(): self
	{
		$this->withCheckAccess = false;

		return $this;
	}

	/**
	 * For updates that keep both the message text and the files it carries unchanged: recent freshness
	 * and the search index follow those, while the update event and the sync log do not.
	 */
	public function withoutTextDerivedUpdate(): self
	{
		$this->withTextDerivedUpdate = false;

		return $this;
	}

	public function update(array $fieldsToUpdate): Result
	{
		if ($this->withCheckAccess && !$this->canUpdate())
		{
			return (new Result())->addError(new Message\MessageError(Message\MessageError::ACCESS_DENIED));
		}

		$filesUpdater = Files\FilesUpdater::createFromFields($this->message, $fieldsToUpdate);
		if ($filesUpdater !== null && !Features::isEditMessageFilesAvailable())
		{
			return (new Result())->addError(new FileError(FileError::FEATURE_DISABLED));
		}

		$previousMessage = clone $this->message;
		$previousFileIds = $this->getMessageFileIds($this->message);

		$result = $this->fillBuilder($fieldsToUpdate);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$this->message->fill($result->getResult());

		if ($filesUpdater !== null)
		{
			$filesResult = $filesUpdater->applyBeforeSave();
			if (!$filesResult->isSuccess())
			{
				return $filesResult;
			}
		}

		if ($this->message->isCompletelyEmpty())
		{
			$messageToDelete = $this->message;
			if ($filesUpdater !== null)
			{
				// the applied composition lives in memory: deletion reads the stored one to clean links up
				$messageToDelete = (new Message($this->message->getMessageId()))
					->withContext($this->message->getContext())
				;
			}

			return DeleteService::getInstanceByMessage($messageToDelete)->delete();
		}

		if ($this->message->isViewedByOthers())
		{
			$this->message->getParams()->get(Params::IS_EDITED)->setValue(true);
		}

		$this->message->autocompleteParams($this->sendingConfig)->uploadFileFromText();
		$result = $this->message->save();
		if (!$result->isSuccess())
		{
			$filesUpdater?->rollbackPreparedFiles();

			return $result;
		}

		$areLinksSynchronized = false;
		if ($filesUpdater !== null)
		{
			$syncLinksResult = $filesUpdater->syncLinks();
			$areLinksSynchronized = $syncLinksResult->isSuccess();
			$result->addErrors($syncLinksResult->getErrors());
		}

		$this->message->getChat()->sendPushUpdateMessage($this->message, $filesUpdater !== null);
		// an emptied composition reaches the clients only as an explicit empty value, and both publications carry it:
		// the sets of recipients of the two do not match, so neither of them may be the only one to say it
		(new Message\Param\PushService())->sendPull($this->message, ['KEYBOARD', 'ATTACH', 'MENU', Params::FILE_ID]);

		// a failed step leaves the queue of the scenario on itself: called out of turn, the finalization would answer
		// with an internal refusal of its own and the client would get it instead of the real cause
		if ($filesUpdater !== null && $areLinksSynchronized)
		{
			$result->addErrors($filesUpdater->finalize()->getErrors());
		}

		(new UrlService())->updateUrlsFromMessage($this->message);
		// the file scenario has already brought the links to the composition it applied, so the generic
		// synchronization is left only what that scenario never saw - the files the text of the message brought in
		$linkedFileIds = ($filesUpdater !== null && $areLinksSynchronized)
			? $filesUpdater->getSynchronizedFileIds()
			: $previousFileIds
		;
		(new FileService())->synchronizeFilesFromMessage(
			$linkedFileIds,
			$this->getMessageFileIds($this->message),
			$this->message,
		);

		$this->onAfterMessageUpdate();

		// update mentions after all events
		(new Message\Send\MentionService())->onMessageUpdate($this->message, $previousMessage);

		return $result;
	}

	/**
	 * @return int[]
	 */
	private function getMessageFileIds(Message $message): array
	{
		$attachmentFileIds = $message->getParams()->isSet(Params::FILE_ID)
			? $message->getParams()->get(Params::FILE_ID)->getValue()
			: [];
		$builderFileIds = $message->getBlocksBuilder()?->getFiles() ?? [];

		return array_values(array_unique(array_map('intval', array_merge($attachmentFileIds, $builderFileIds))));
	}

	public function onAfterMessageUpdate(): void
	{
		if ($this->withTextDerivedUpdate)
		{
			$this->updateTextDerivedState();
		}

		$this->fireEventAfterMessageUpdate();
		$this->message->getChat()->onAfterMessageUpdate($this->message);
	}

	private function updateTextDerivedState(): void
	{
		Application::getConnection()->queryExecute("
			UPDATE b_im_recent
			SET DATE_UPDATE = NOW()
			WHERE ITEM_MID = " . $this->message->getId(),
		);

		MessageTable::indexRecord($this->message->getId());
	}

	public function canUpdate(): bool
	{
		$isMessageDelete = $this->message->getParams()->get(Params::IS_DELETED)->getValue() === true;
		$isForward = $this->message->getParams()->isSet(Params::FORWARD_ID);
		$isContactMessage = ContactMessage::is($this->message);

		if ($isMessageDelete || $isForward || $isContactMessage)
		{
			return false;
		}

		$user = $this->getContext()->getUser();
		$chat = $this->message->getChat();

		if ($chat instanceof Chat\OpenLineChat && Loader::includeModule('imopenlines'))
		{
			if ($user->isBot())
			{
				return true;
			}

			if ($user->getId() === $this->message->getAuthorId())
			{
				return $chat->canUpdateOwnMessage();
			}

			return false;
		}

		if ($this->message->getAuthorId() === $user->getId())
		{
			return true;
		}

		return false;
	}

	private function getBotInChat(): array
	{
		$result = [];
		$users = $this->message->getChat()->getRelations()->getUsers();

		foreach ($users as $user)
		{
			if ($user->isBot())
			{
				$result[$user->getId()] = $user->getId();
			}
		}

		return $result;
	}

	private function fireEventAfterMessageUpdate(): void
	{
		$chat = $this->message->getChat();
		$messageFields = [
			'ID' => $this->message->getId(),
			'CHAT_ID' => $this->message->getChatId(),
			'AUTHOR_ID' => $this->message->getAuthorId(),
			'MESSAGE' => $this->message->getMessage(),
			'MESSAGE_OUT' => $this->message->getMessageOut(),
			'DATE_CREATE' => $this->message->getDateCreate()?->getTimestamp(),
			'EMAIL_TEMPLATE' => $this->message->getEmailTemplate(),
			'NOTIFY_TYPE' => $this->message->getNotifyType(),
			'NOTIFY_MODULE' => $this->message->getNotifyModule(),
			'NOTIFY_EVENT' => $this->message->getNotifyEvent(),
			'NOTIFY_TAG' => $this->message->getNotifyTag(),
			'NOTIFY_SUB_TAG' => $this->message->getNotifySubTag(),
			'NOTIFY_TITLE' => $this->message->getNotifyTitle(),
			'NOTIFY_BUTTONS' => $this->message->getNotifyButtons(),
			'NOTIFY_READ' => $this->message->isNotifyRead(),
			'IMPORT_ID' => $this->message->getImportId(),
			'MESSAGE_TYPE' => $chat->getType(),
			'CHAT_AUTHOR_ID' => $chat->getAuthorId(),
			'CHAT_ENTITY_TYPE' => $chat->getEntityType(),
			'CHAT_ENTITY_ID' => $chat->getEntityId(),
			'CHAT_PARENT_ID' => $chat->getParentChatId(),
			'CHAT_PARENT_MID' => $chat->getParentMessageId(),
			'CHAT_ENTITY_DATA_1' => $chat->getEntityData1(),
			'CHAT_ENTITY_DATA_2' => $chat->getEntityData2(),
			'CHAT_ENTITY_DATA_3' => $chat->getEntityData3(),
			// params of the event go through the same border as rest and pull, or the composition loses its order
			'PARAMS' => $this->message->getEnrichedParams(false)->toRestFormat(),
			'DATE_MODIFY' => new DateTime(),
		];

		if ($chat instanceof Chat\PrivateChat)
		{
			$messageFields['FROM_USER_ID'] = $this->message->getAuthorId();
			$messageFields['TO_USER_ID'] = $chat->getCompanion($this->message->getAuthorId())->getId();
		}
		else
		{
			$messageFields['BOT_IN_CHAT'] = $this->getBotInChat();
		}

		$messageFields['REPLY_MESSAGE'] = Message\Send\Event\MessageEventLegacy::getReplyField($this->message);

		$updateFlags = [
			'ID' => $this->message->getId(),
			'TEXT' => $this->message->getMessage(),
			'URL_PREVIEW' => $this->urlPreview,
			'EDIT_FLAG' => $this->message->getParams()->get(Params::IS_EDITED)->getValue(),
			'USER_ID' => $this->message->getAuthorId(),
			'BY_EVENT' => $this->byEvent,
		];

		foreach(GetModuleEvents('im', self::EVENT_AFTER_MESSAGE_UPDATE, true) as $event)
		{
			ExecuteModuleEventEx($event, [$this->message->getId(), $messageFields, $updateFlags]);
		}

		Bot::onMessageUpdate($this->message->getId(), $messageFields, $this->message);

		try
		{
			(new \Bitrix\Im\V2\EventLog\EventLogger())->logUserMessageEvent(
				'ONIMV2MESSAGEUPDATE',
				fn() => (new \Bitrix\Im\V2\Event\EventPayload())->messageUpdate($this->message->getId(), $messageFields),
				$messageFields,
			);
		}
		catch (\Throwable)
		{
		}
	}

	private function fillBuilder(array $fieldsToUpdate): Result
	{
		$result = new Result();
		if (!isset($fieldsToUpdate['BLOCK']) || !is_array($fieldsToUpdate['BLOCK']))
		{
			return $result->setResult($fieldsToUpdate);
		}

		$chat = $this->message->getChat();
		$builderService = ServiceLocator::getInstance()->get(BuilderService::class);
		$builderResult = $builderService->create($fieldsToUpdate['BLOCK'], $chat, $this->message->getBlocksBuilder());
		if (!$builderResult->isSuccess())
		{
			return $result->addError($builderResult->getError());
		}

		$builder = $builderResult->getBlocksBuilder();
		$fieldsToUpdate['PARAMS'][Params::BLOCK] = $builder;
		$fieldsToUpdate['MESSAGE'] = $builder->getPayloadText();
		$this->message->setBlocksBuilder($builder);
		unset($fieldsToUpdate['BLOCK']);

		return $result->setResult($fieldsToUpdate);
	}
}
