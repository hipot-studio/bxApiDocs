<?php

namespace Bitrix\Im\V2\Link\Pin;

use Bitrix\Im\Dialog;
use Bitrix\Im\Model\LinkPinTable;
use Bitrix\Im\Services\MessageParam;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity;
use Bitrix\Im\V2\Link\Push;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Im\V2\Sync;

class PinService
{
	use ContextCustomer;

	public const ADD_PIN_EVENT = 'pinAdd';
	public const DELETE_PIN_EVENT = 'pinDelete';

	private const UNPIN_NOTIFICATION_DISABLED_OPTION = 'message_unpin_notification_disabled';

	public function pinMessage(Message $message): Result
	{
		$result = new  Result();

		if ($this->isMessageOutOfReach($message))
		{
			return $result->addError(new Message\MessageError(Message\MessageError::MESSAGE_NOT_FOUND));
		}

		// A pin that is already there is answered as such even in a chat filled to the ceiling:
		// otherwise the author is told to unpin something, and unpinning would change nothing.
		if (PinItem::getByMessage($message) !== null)
		{
			return $result->addError(new Message\MessageError(Message\MessageError::MESSAGE_IS_ALREADY_PIN));
		}

		$chatId = $message->getChatId() ?? 0;

		// Counted over the whole chat and not over the history window of this member on purpose:
		// START_ID belongs to the member, so a member who joined with the history closed would see
		// no pins and put their own twenty on top of the twenty already there.
		if (PinLimits::isCommonLimitReached($this->getCount($chatId)))
		{
			return $result->addError(new Message\MessageError(
				Message\MessageError::PIN_LIMIT_REACHED,
				['limit' => PinLimits::MAX_PINS_PER_CHAT]
			));
		}

		$pin = PinItem::createFromMessage($message, $this->getContext());
		$saveResult = $this->savePin($pin);

		if (!$saveResult->isSuccess())
		{
			return $result->addErrors($saveResult->getErrors());
		}

		$saveParamResult = $this->saveInParam($message);

		if (!$saveParamResult->isSuccess())
		{
			return $result->addErrors($saveParamResult->getErrors());
		}

		$chat = Chat::getInstance($pin->getChatId());
		$messageId = $message->getMessageId();
		$recipients = $this->filterRecipientsByStartId($chat, $messageId);

		Sync\Logger::getInstance()->add(
			new Sync\Event(Sync\Event::ADD_EVENT, Sync\Event::PIN_MESSAGE_ENTITY, $pin->getId()),
			fn () => $recipients,
			$chat
		);

		$this->sendMessageAboutPin($pin);
		$this->sendParamPull($message, $recipients);

		Push::getInstance()
			->setContext($this->context)
			->sendFull($pin, static::ADD_PIN_EVENT, ['CHAT_ID' => $chat->getId(), 'RECIPIENT' => $recipients])
		;

		return $result;
	}

	public function unpinMessage(Message $message): Result
	{
		$result = new Result();

		if ($this->isMessageOutOfReach($message))
		{
			return $result->addError(new Message\MessageError(Message\MessageError::MESSAGE_NOT_FOUND));
		}

		$pin = PinItem::getByMessage($message);

		if ($pin === null)
		{
			return $result;
		}

		// A concurrent request has already removed this pin, so the side effects of the unpin are its to publish.
		if (!$pin->tryDelete())
		{
			return $result;
		}

		$deleteParamResult = $this->deleteFromParam($message);

		if (!$deleteParamResult->isSuccess())
		{
			return $result->addErrors($deleteParamResult->getErrors());
		}

		$chat = Chat::getInstance($pin->getChatId());
		$messageId = $message->getMessageId();
		$recipients = $this->filterRecipientsByStartId($chat, $messageId);

		Sync\Logger::getInstance()->add(
			new Sync\Event(Sync\Event::DELETE_EVENT, Sync\Event::PIN_MESSAGE_ENTITY, $pin->getId()),
			fn () => $recipients,
			$chat
		);

		$this->sendParamPull($message, $recipients);

		Push::getInstance()
			->setContext($this->context)
			->sendIdOnly($pin, static::DELETE_PIN_EVENT, ['CHAT_ID' => $chat->getId(), 'RECIPIENT' => $recipients])
		;

		if ($this->needToSendMessageAboutUnpin($chat))
		{
			$this->sendMessageAboutUnpin($pin);
		}

		return $result;
	}

	public function unpinMessages(MessageCollection $messages, bool $clearParams = true): Result
	{
		$result = new Result();

		$pinCollection = PinCollection::getByMessages($messages);

		if ($pinCollection->count() === 0)
		{
			return $result;
		}

		$deleteResult = $pinCollection->delete();

		if (!$deleteResult->isSuccess())
		{
			return $result->addErrors($deleteResult->getErrors());
		}

		if ($clearParams)
		{
			$deleteParamResult = $this->deleteFromParams($messages);

			if (!$deleteParamResult->isSuccess())
			{
				return $result->addErrors($deleteParamResult->getErrors());
			}
		}


		$chat = Chat::getInstance($pinCollection->getRelatedChatId());

		/** @var PinItem $pin */
		foreach ($pinCollection as $pin)
		{
			Sync\Logger::getInstance()->add(
				new Sync\Event(Sync\Event::DELETE_EVENT, Sync\Event::PIN_MESSAGE_ENTITY, $pin->getId()),
				fn () => $chat->getRelations()->getUserIds(),
				$chat
			);

			Push::getInstance()
				->setContext($this->context)
				->sendIdOnly($pin, static::DELETE_PIN_EVENT, ['CHAT_ID' => $pin->getChatId()])
			;
		}

		return $result;
	}

	/**
	 * The row of the message is asked for, and the reference is an inner join: a pin left over from a
	 * message that is already gone points at nothing a reader can open, so it must not hold a place in
	 * the quota of the chat.
	 */
	public function getCount(int $chatId, ?int $startId = null): int
	{
		$filter = Query::filter()
			->where('CHAT_ID', $chatId)
			->whereNotNull('MESSAGE.ID')
		;

		if (isset($startId) && $startId > 0)
		{
			$filter->where('MESSAGE_ID', '>=', $startId);
		}

		return LinkPinTable::getCount($filter);
	}

	protected function savePin(PinItem $pin): Result
	{
		try
		{
			return $pin->save();
		}
		catch (\Bitrix\Main\SystemException $exception)
		{
			return (new Result())->addError(new Message\MessageError(Message\MessageError::MESSAGE_IS_ALREADY_PIN));
		}
	}

	protected function saveInParam(Message $message): Result
	{
		\CIMMessageParam::Set($message->getMessageId(), ['IS_PINNED' => 'Y']);
		$this->syncPinnedParam($message, 'Y');

		return new Result();
	}

	protected function deleteFromParam(Message $message): Result
	{
		\CIMMessageParam::Set($message->getMessageId(), ['IS_PINNED' => 'N']);
		$this->syncPinnedParam($message, 'N');

		return new Result();
	}

	/**
	 * The pull reads the params of the message object, so a set loaded before the change would carry the
	 * previous IS_PINNED. A set that is not loaded stays that way: it is read from the database on
	 * demand, where the value is already up to date.
	 */
	private function syncPinnedParam(Message $message, string $value): void
	{
		$params = $message->getParams(true);

		if ($params->isLoaded())
		{
			$params->get(Message\Params::IS_PINNED)->setValue($value);
		}
	}

	protected function sendParamPull(Message $message, array $recipients): void
	{
		if (empty($recipients) || !\Bitrix\Main\Loader::includeModule('pull'))
		{
			return;
		}

		$pushService = new Message\Param\PushService();
		$pushService->sendPull($message, ['IS_PINNED'], $recipients);
	}

	/**
	 * The params of the whole collection are read in one query, so the value can be corrected in place
	 * instead of replacing the set of every message with a single IS_PINNED.
	 */
	protected function deleteFromParams(MessageCollection $messages): Result
	{
		$messages->fillParams();

		foreach ($messages as $message)
		{
			$this->syncPinnedParam($message, 'N');
			$this->sendParamPull(
				$message,
				$this->filterRecipientsByStartId($message->getChat(), $message->getMessageId())
			);
		}

		return new Result();
	}

	protected function sendMessageAboutPin(PinItem $pin): Result
	{
		return $this->sendMessageAboutPinChange($pin, $this->getPinAddPhraseCode($pin));
	}

	protected function sendMessageAboutUnpin(PinItem $pin): Result
	{
		return $this->sendMessageAboutPinChange($pin, $this->getPinDeletePhraseCode($pin));
	}

	/**
	 * Only a person removing one named pin notifies: the bulk and cascade paths of unpinMessages()
	 * never reach this predicate.
	 */
	private function needToSendMessageAboutUnpin(Chat $chat): bool
	{
		if (
			$chat instanceof Chat\OpenLineChat
			|| $chat instanceof Chat\OpenLineLiveChat
			|| $chat instanceof Chat\CommentChat
		)
		{
			return false;
		}

		$context = $this->getContext();

		if ($context->getUser()->isBot() || $context->getUserId() === Entity\User\User::SYSTEM_USER_ID)
		{
			return false;
		}

		if (Option::get('im', self::UNPIN_NOTIFICATION_DISABLED_OPTION, 'N') === 'Y')
		{
			return false;
		}

		return true;
	}

	private function sendMessageAboutPinChange(PinItem $pin, string $phraseCode): Result
	{
		//todo: Replace with new API
		$authorId = $this->getContext()->getUserId();
		// Without the second argument the id is read from the global user and comes back empty when there is none.
		$dialogId = Dialog::getDialogId($pin->getChatId(), $authorId);

		$messageId = \CIMChat::AddMessage([
			'DIALOG_ID' => $dialogId,
			'SYSTEM' => 'Y',
			'MESSAGE' => $this->getPinMessageText($pin, $phraseCode),
			'FROM_USER_ID' => $authorId,
			'PARAMS' => [
				'CLASS' => 'bx-messenger-content-item-system',
				'BETA' => 'Y'
			],
			'URL_PREVIEW' => 'N',
			'SKIP_CONNECTOR' => 'Y',
			'SKIP_COMMAND' => 'Y',
			'SILENT_CONNECTOR' => 'Y',
			'WAIT_FULL_EXECUTION' => 'N',
		]);

		$result = new Result();

		if ($messageId === false)
		{
			// The caller drops this result on purpose: the log is the only trace of an unpublished notice.
			(new LoggerFactory())->createById('im.Pin.Notification')?->error(
				'Failed to publish the system message about the pin change',
				[
					'chatId' => $pin->getChatId(),
					'pinnedMessageId' => $pin->getMessageId(),
					'authorId' => $authorId,
					'dialogId' => $dialogId,
					'phraseCode' => $phraseCode,
				],
			);

			return $result->addError(new Message\MessageError(Message\MessageError::SENDING_FAILED));
		}

		return $result;
	}

	protected function filterRecipientsByStartId(Chat $chat, int $messageId): array
	{
		// Besides the personal border of every member, the border a cleanup for everyone leaves on the
		// chat: the relations behind it are moved by a walk that finishes in the background, and until it
		// reaches them the update would go to members the message is already gone for.
		if ($chat->isMessageBehindHistoryBorder($messageId))
		{
			return [];
		}

		$recipients = [];
		foreach ($chat->getRelations() as $relation)
		{
			$startId = $relation->getStartId() ?? 0;
			if ($startId <= $messageId)
			{
				$recipients[] = $relation->getUserId();
			}
		}

		// A nested chat inherits access from its parent, so a user who lost the parent membership
		// drops out of the audience: the legacy pin push did the same through GetRelationById().
		return $chat->getRelationFacade()?->filterUserIdsByAccess($recipients) ?? $recipients;
	}

	/**
	 * A message behind the history border is gone for its reader from the moment the border is set, long
	 * before the scheduled cleanup takes it away. Pinning quotes the text of the message into a system
	 * message the whole chat reads, so the border has to hold here as it does on the reading paths: the
	 * action filter of the controller knows the rights of the chat and nothing of the message.
	 */
	private function isMessageOutOfReach(Message $message): bool
	{
		return $message->getChat()->isMessageBehindUserHistoryBorder(
			(int)$message->getMessageId(),
			$this->getContext()->getUserId()
		);
	}

	protected function getPinMessageText(PinItem $pin, string $phraseCode): string
	{
		$text = (new Message($pin->getMessageId()))->getQuotedMessage() . "\n";
		$text .= Loc::getMessage(
			$phraseCode,
			[
				'#MESSAGE_ID#' => $pin->getMessageId(),
				'#USER_ID#' => $this->getContext()->getUserId(),
				'#DIALOG_ID#' => Chat::getInstance($pin->getChatId())->getDialogContextId(),
			]
		);

		return $text;
	}

	private function getPinAddPhraseCode(PinItem $pin): string
	{
		if (Chat::getInstance($pin->getChatId()) instanceof Chat\ChannelChat)
		{
			return 'IM_CHANNEL_PIN_ADD_NOTIFICATION' . $this->getPinPhraseGenderModifier();
		}

		return 'IM_CHAT_PIN_ADD_NOTIFICATION' . $this->getPinPhraseGenderModifier() . '_MSGVER_1';
	}

	private function getPinDeletePhraseCode(PinItem $pin): string
	{
		if (Chat::getInstance($pin->getChatId()) instanceof Chat\ChannelChat)
		{
			return 'IM_CHANNEL_PIN_DELETE_NOTIFICATION' . $this->getPinPhraseGenderModifier();
		}

		return 'IM_CHAT_PIN_DELETE_NOTIFICATION' . $this->getPinPhraseGenderModifier();
	}

	private function getPinPhraseGenderModifier(): string
	{
		return $this->getContext()->getUser()->getGender() === 'F' ? '_F' : '';
	}
}