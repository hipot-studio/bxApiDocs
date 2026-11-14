<?php

namespace Bitrix\Im\V2\Link\PersonalPin;

use Bitrix\Im\Model\LinkPinPersonalTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Link\Pin\PinLimits;
use Bitrix\Im\V2\Link\Push;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\MessageError;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Sync;
use Bitrix\Main\Application;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;

/**
 * Personal pin is a silent mark of a message visible to its owner alone. Unlike the common pin it
 * posts no system message and leaves the IS_PINNED param of the message untouched: both of them are
 * seen by every member of the chat.
 */
class PersonalPinService
{
	use ContextCustomer;

	public const ADD_PERSONAL_PIN_EVENT = 'personalPinAdd';
	public const DELETE_PERSONAL_PIN_EVENT = 'personalPinDelete';

	protected const OWNERS_CHUNK_SIZE = 500;
	protected const LIMIT_LOCK_TIMEOUT = 3;

	/**
	 * The one field of the removal payload that belongs to the owner alone; everything else about a
	 * mark of the same message is the same for all of them.
	 */
	protected const OWN_MARK_PAYLOAD_FIELD = 'id';

	public function pinMessage(Message $message): Result
	{
		$result = new Result();

		$ownerId = $this->getContext()->getUserId();
		$chatId = $message->getChatId() ?? 0;

		// A mark that is already there is answered as such even in a chat filled to the ceiling:
		// otherwise the owner is told to unpin something, and unpinning would change nothing.
		if (PersonalPinItem::getByMessageAndOwner($message, $ownerId) !== null)
		{
			return $result->addError(new MessageError(MessageError::MESSAGE_IS_ALREADY_PERSONAL_PIN));
		}

		$connection = Application::getConnection();
		$lockName = $this->getLimitLockName($ownerId, $chatId);
		$isLocked = $connection->lock($lockName, self::LIMIT_LOCK_TIMEOUT);

		try
		{
			if (PinLimits::isPersonalLimitReached($this->getCountForOwner($ownerId, $chatId)))
			{
				return $result->addError(new MessageError(
					MessageError::PERSONAL_PIN_LIMIT_REACHED,
					['limit' => PinLimits::MAX_PERSONAL_PINS_PER_CHAT]
				));
			}

			$pin = PersonalPinItem::createFromMessage($message, $this->getContext());
			$saveResult = $this->savePin($pin);
		}
		finally
		{
			if ($isLocked)
			{
				$connection->unlock($lockName);
			}
		}

		if (!$saveResult->isSuccess())
		{
			return $result->addErrors($saveResult->getErrors());
		}

		$this->logToSync($pin, Sync\Event::ADD_EVENT);
		$this->sendAddEvent($pin);

		return $result;
	}

	public function unpinMessage(Message $message): Result
	{
		$result = new Result();

		$pin = PersonalPinItem::getByMessageAndOwner($message, $this->getContext()->getUserId());

		if ($pin === null)
		{
			return $result;
		}

		$deleteResult = $pin->delete();

		if (!$deleteResult->isSuccess())
		{
			return $result->addErrors($deleteResult->getErrors());
		}

		$this->logToSync($pin, Sync\Event::DELETE_EVENT);
		$this->sendDeleteEvent($pin);

		return $result;
	}

	/**
	 * Counterpart of unpinMessage for deleted messages: a mark on a message that no longer exists is
	 * dropped for every owner of it. Each of them still gets their own journal row and their own
	 * payload, so the mark leaves the list and the header without a reload of the chat.
	 *
	 * Owners are walked by chunks: their number is bounded by the size of the chat and not by the
	 * ceiling of pins, so a message of a channel may hold as many marks as it has members.
	 */
	public function unpinMessagesForAllOwners(MessageCollection $messages): Result
	{
		$result = new Result();

		$messageIds = $messages->getIds();

		if (empty($messageIds))
		{
			return $result;
		}

		$lastMessageId = 0;
		$lastAuthorId = 0;

		while (true)
		{
			$pins = $this->getByMessagesForAllOwners($messageIds, $lastMessageId, $lastAuthorId);
			$pinsInChunk = $pins->count();

			if ($pinsInChunk === 0)
			{
				return $result;
			}

			/** @var PersonalPinItem $pin */
			foreach ($pins as $pin)
			{
				$messageId = (int)$pin->getMessageId();
				$authorId = (int)$pin->getAuthorId();

				if ($messageId > $lastMessageId || ($messageId === $lastMessageId && $authorId > $lastAuthorId))
				{
					$lastMessageId = $messageId;
					$lastAuthorId = $authorId;
				}
			}

			$deleteResult = $pins->delete();

			if (!$deleteResult->isSuccess())
			{
				return $result->addErrors($deleteResult->getErrors());
			}

			/** @var PersonalPinItem $pin */
			foreach ($pins as $pin)
			{
				$this->logToSync($pin, Sync\Event::DELETE_EVENT);
			}

			$this->sendDeleteEventToOwners($pins);

			// the chunk bounds the read alone: the journal holds the rows of every chunk until the
			// background job of the hit, and a message of a crowded channel fills it with all of them
			Sync\Logger::getInstance()->flush();

			if ($pinsInChunk < self::OWNERS_CHUNK_SIZE)
			{
				return $result;
			}
		}
	}

	/**
	 * The only read of personal pins that crosses owners, and it stays protected for that reason:
	 * the message is gone for everybody, so every mark on it has to go too. Public reads stay
	 * narrowed by owner.
	 *
	 * The ceiling of personal pins is counted per owner and does not bound this read: a message of a
	 * channel may carry a mark of every member of it. Hence the page and the cursor - the caller walks
	 * the owners by chunks instead of holding all of them at once. The cursor is the pair the unique
	 * index is built on, so the same index serves both the filter and the order.
	 */
	protected function getByMessagesForAllOwners(array $messageIds, int $lastMessageId, int $lastAuthorId): PersonalPinCollection
	{
		if (empty($messageIds))
		{
			return new PersonalPinCollection();
		}

		$query = LinkPinPersonalTable::query()
			->setSelect(['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE_ID'])
			->whereIn('MESSAGE_ID', $messageIds)
			->setOrder(['MESSAGE_ID' => 'ASC', 'AUTHOR_ID' => 'ASC'])
			->setLimit(self::OWNERS_CHUNK_SIZE)
		;

		if ($lastMessageId > 0)
		{
			$query->where(Query::filter()
				->logic('or')
				->where('MESSAGE_ID', '>', $lastMessageId)
				->where(Query::filter()
					->where('MESSAGE_ID', '=', $lastMessageId)
					->where('AUTHOR_ID', '>', $lastAuthorId)
				)
			);
		}

		$entities = $query->fetchCollection();

		if ($entities === null)
		{
			return new PersonalPinCollection();
		}

		return PersonalPinCollection::initByEntityCollection($entities);
	}

	/**
	 * Counting and inserting are two queries, so two requests of the same owner would both pass a count
	 * of nineteen and leave twenty one marks: the unique index guards the pair of message and owner and
	 * says nothing about their number. The lock is named by the pair the ceiling is counted over, so
	 * owners and chats never wait for each other. A lock that could not be taken in time does not refuse
	 * the owner: the race it protects from costs one mark above the ceiling, a refusal costs the mark
	 * itself.
	 */
	protected function getLimitLockName(int $authorId, int $chatId): string
	{
		return "im_personal_pin_limit_{$chatId}_{$authorId}";
	}

	/**
	 * The owner is an argument of the count and not a key of an optional filter: a count without an
	 * owner would expose the marks of other members. The history window is left out on purpose - a
	 * count over the visible part alone would let the owner walk around their own quota.
	 *
	 * The row of the message is asked for, and the reference is an inner join: a mark whose message is
	 * already gone leaves the list of the owner the same way and must not hold a place in the quota
	 * either. It is the existence of the message that is checked here, not the right to read it.
	 */
	protected function getCountForOwner(int $authorId, int $chatId): int
	{
		$filter = Query::filter()
			->where('AUTHOR_ID', $authorId)
			->where('CHAT_ID', $chatId)
			->whereNotNull('MESSAGE.ID')
		;

		return LinkPinPersonalTable::getCount($filter);
	}

	/**
	 * The journal row goes to the owner alone, where the common pin writes one to every member of
	 * the chat. The chat is still passed: it decides whether the journal is kept for this chat at all.
	 */
	protected function logToSync(PersonalPinItem $pin, string $syncEvent): void
	{
		Sync\Logger::getInstance()->add(
			new Sync\Event($syncEvent, Sync\Event::PERSONAL_PIN_MESSAGE_ENTITY, $pin->getId()),
			$pin->getAuthorId(),
			Chat::getInstance($pin->getChatId())
		);
	}

	/**
	 * Two failures reach this catch and they end differently for the owner. A race on the unique index
	 * means the mark is there after all, and both clients read that code as success: the optimistic mark
	 * they already drew stays. Any other failure of the base saved nothing, so it has to come back as a
	 * failure - under the code of a mark already made the clients would keep a mark the base does not
	 * hold, and it would vanish on the next load. A personal mark writes no system message and no
	 * message param, so nothing but the log would show a real failure happened.
	 */
	protected function savePin(PersonalPinItem $pin): Result
	{
		try
		{
			return $pin->save();
		}
		catch (DuplicateEntryException)
		{
			return (new Result())->addError(new MessageError(MessageError::MESSAGE_IS_ALREADY_PERSONAL_PIN));
		}
		catch (SystemException $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return (new Result())->addError(new MessageError(MessageError::PERSONAL_PIN_FAILED));
		}
	}

	/**
	 * The owner is addressed directly. A chat endpoint is not an option: in a comment chat the
	 * transport replaces the recipient with the watch tag of the thread, and the mark would reach
	 * everyone watching it.
	 */
	protected function sendAddEvent(PersonalPinItem $pin): void
	{
		Push::getInstance()
			->setContext($this->context)
			->sendFull($pin, static::ADD_PERSONAL_PIN_EVENT, ['RECIPIENT' => [$pin->getAuthorId()]])
		;
	}

	protected function sendDeleteEvent(PersonalPinItem $pin): void
	{
		Push::getInstance()
			->setContext($this->context)
			->sendIdOnly($pin, static::DELETE_PERSONAL_PIN_EVENT, ['RECIPIENT' => [$pin->getAuthorId()]])
		;
	}

	/**
	 * Counterpart of sendDeleteEvent for the cascade, where the same message is marked by many owners.
	 * The owners of one message differ in the id of their own mark and in nothing else, so the removal
	 * travels as one event per message: the rest of the payload is shared and the ids go in the map by
	 * owner. An event per owner would hold a payload per member of the chat in the buffer of the hit.
	 */
	protected function sendDeleteEventToOwners(PersonalPinCollection $pins): void
	{
		foreach ($this->groupByMessage($pins) as $pinsOfMessage)
		{
			$sharedParams = $pinsOfMessage[array_key_first($pinsOfMessage)]->toRestFormatIdOnly();
			unset($sharedParams[self::OWN_MARK_PAYLOAD_FIELD]);

			$paramsByOwner = [];

			foreach ($pinsOfMessage as $pin)
			{
				$payload = $pin->toRestFormatIdOnly();
				$paramsByOwner[(int)$pin->getAuthorId()] = [
					self::OWN_MARK_PAYLOAD_FIELD => $payload[self::OWN_MARK_PAYLOAD_FIELD],
				];
			}

			Push::getInstance()
				->setContext($this->context)
				->sendPersonalized($sharedParams, static::DELETE_PERSONAL_PIN_EVENT, $paramsByOwner)
			;
		}
	}

	/**
	 * @return array<int, PersonalPinItem[]>
	 */
	protected function groupByMessage(PersonalPinCollection $pins): array
	{
		$byMessage = [];

		/** @var PersonalPinItem $pin */
		foreach ($pins as $pin)
		{
			$byMessage[(int)$pin->getMessageId()][] = $pin;
		}

		return $byMessage;
	}
}
