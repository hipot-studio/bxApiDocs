<?php

namespace Bitrix\StaffTrack\Internal\Service\CheckIn;

use Bitrix\Main\Application;
use Bitrix\Main\Authentication\Internal\UserHitAuthTable;
use Bitrix\Main\Event;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Dictionary\Option;
use Bitrix\StaffTrack\Internal\Entity\HandoffIntent;
use Bitrix\StaffTrack\Internal\Integration\Im\MessageService;
use Bitrix\StaffTrack\Model\OptionTable;
use Bitrix\StaffTrack\Provider\OptionProvider;
use Bitrix\StaffTrack\Service\OptionService;

/**
 * The life cycle of a check-in handoff intent: bind it to the code the transition link was issued with,
 * activate it when the sign-in came by that very code, hand it over once and forget it.
 *
 * One intent per person, kept as a user option of the domain rather than in a cache: a record lost from
 * a cache would not fail, it would silently name the wrong chat. The next issue of a code overwrites the
 * record unless it is the reissue of a chat already entered, and an expired one is dropped by the
 * reading that met it - nothing sweeps them in the background, and the reading is the only visit such a
 * record ever gets.
 */
class HandoffIntentService
{
	/**
	 * The path an intent of this kind is always taken by: a chat picked on the portal and entered by the
	 * transition code. The server states it rather than reads it from the payload - the funnel is counted
	 * by this value, and the client is in no position to name the way its own request arrived.
	 */
	public const SOURCE_CHAT_ATTACH_QR = 'chat_attach_qr';

	/**
	 * How long an intent lives when the code it is bound to carries no expiry of its own. Such a code
	 * outlives any intent anyway, so the record is bounded by the reissue period of the popup instead.
	 */
	private const DEFAULT_TTL_SEC = 60;

	/**
	 * How long an activated intent lives, counted from the sign-in rather than from the issue of the code.
	 * Until the sign-in the intent must not outlive the code, and it does not; afterwards the code is
	 * consumed and deleted, and what is left to wait for is the cold start of the application - the load
	 * of the section handler and of the check-in extension over a mobile network. The code alone never
	 * covered it: what it had left after a scan was whatever the popup had not yet spent of its minute,
	 * which on a live device is regularly less than the start itself takes.
	 */
	private const ACTIVATED_TTL_SEC = 180;

	/** The width of OptionTable::VALUE. A record that does not fit loses its chat ({@see store()}). */
	private const MAX_VALUE_LENGTH = 255;

	/** The name the handing over of an intent is serialized on; a record belongs to one person. */
	private const CONSUME_LOCK_PREFIX = 'stafftrack_check_in_handoff_intent_';

	/**
	 * How long a consumption waits for the one already running. The holder only reads and deletes a
	 * single row, so the wait is there for an overlap of milliseconds and not for a queue.
	 */
	private const CONSUME_LOCK_WAIT_SEC = 2;

	/**
	 * How many times a writing of an issue reads the record anew after another request changed it in
	 * between ({@see store()}). The rivals are a sign-in, a consumption and the next issue of the popup -
	 * a few of them at most, so a record still moving after that many readings is left to the requests
	 * moving it.
	 */
	private const STORE_ATTEMPTS = 3;

	private OptionService $optionService;
	private OptionProvider $optionProvider;

	public function __construct()
	{
		$this->optionService = OptionService::getInstance();
		$this->optionProvider = OptionProvider::getInstance();
	}

	/**
	 * The transition link was issued with a payload addressed to this module. The event is delivered from
	 * inside the issue itself, so an exception here would turn a failure of the domain into a broken
	 * transition code - which is why nothing leaves this method.
	 */
	public static function onDeeplinkPayloadIssued(Event $event): void
	{
		$hash = (string)$event->getParameter('hash');
		$data = $event->getParameter('data');

		try
		{
			(new self())->rememberFromPayload(
				(int)$event->getParameter('userId'),
				$hash,
				is_array($data) ? $data : [],
			);
		}
		catch (\Throwable $error)
		{
			self::report('onDeeplinkPayloadIssued', $error, $hash);
		}
	}

	/**
	 * A one-time code was consumed by a sign-in. The kernel fires this through ExecuteModuleEventEx, so
	 * the handler is called with plain arguments and not with an event object; the reissue of a code
	 * suppresses the event, therefore it means the sign-in itself.
	 *
	 * The event comes for the codes of every scenario, and an intent bound to another code is none of
	 * ours. The handler runs inside the sign-in, so its failures stay here as well.
	 */
	public static function onOneTimeHashRemoved($userId, $hash): void
	{
		$hashValue = (string)$hash;

		try
		{
			(new self())->activate((int)$userId, $hashValue);
		}
		catch (\Throwable $error)
		{
			self::report('onOneTimeHashRemoved', $error, $hashValue);
		}
	}

	/**
	 * Binds the chat of the payload to the code the link was issued with. The chat comes from the client
	 * and its name is resolved back by the server, so a chat the sender is not in does not travel: what
	 * takes the place of the record is the expectation of a chat alone ({@see rememberExpectation()}).
	 *
	 * A payload without a chat is not such a transition at all: it names nothing to expect and nothing to
	 * tell a new transition from a reissue by, so the record lying there is left alone. A code that is
	 * already over leaves it alone as well - such a code signs nobody in, and the next reissue of the
	 * popup writes the record of one that does.
	 *
	 * The code of this issue may be over before its record is written: the issues of the popup overlap,
	 * and the next one deletes this code before it issues its own. So the code is looked up once more
	 * right before the writing ({@see store()}).
	 */
	public function rememberFromPayload(int $userId, string $hash, array $data): bool
	{
		$dialogId = trim((string)($data['dialogId'] ?? ''));
		if ($dialogId === '' || $userId <= 0 || $hash === '')
		{
			return false;
		}

		$expiresAt = $this->resolveExpiry($userId, $hash);

		if (!$this->isChatDeliverable($dialogId, $userId, $hash))
		{
			return $this->rememberExpectation($userId, $hash, $dialogId, self::SOURCE_CHAT_ATTACH_QR, $expiresAt);
		}

		return $this->store(
			$userId,
			$dialogId,
			new HandoffIntent(HandoffIntent::digestOf($hash), $dialogId, self::SOURCE_CHAT_ATTACH_QR, $expiresAt),
			$hash,
		);
	}

	/**
	 * Writes the intent of the person, replacing the one left by the previous issue of a code
	 * ({@see store()}).
	 */
	public function remember(int $userId, string $hash, string $dialogId, string $source, int $expiresAt): bool
	{
		return $this->store(
			$userId,
			$dialogId,
			new HandoffIntent(HandoffIntent::digestOf($hash), $dialogId, $source, $expiresAt),
		);
	}

	/**
	 * Marks the intent as entered by its own code. The record is read by the owner of the event, so an
	 * intent of somebody else is out of reach here, and one bound to another code is left untouched.
	 *
	 * The record was written by the request that issued the code, and the popup reissues by a timer
	 * nobody watching it knows about: a scan right after a reissue meets a record milliseconds old. So
	 * this reading goes to the master as well ({@see readStoredFromMaster()}) - a replica behind by that much
	 * would answer with nothing, the intent would stay unentered and the chat would never be handed over.
	 *
	 * Between that reading and the writing of it an issue of a code lands with a record of its own: the
	 * person picked another chat on the portal while this sign-in was running. So the record read is what
	 * the writing replaces and nothing else ({@see OptionService::replaceIfEquals()}) - an entered chat
	 * written over the later issue would be handed to the application instead of the chat that issue was
	 * made for. A writing that did not happen answers false and leaves that record alone: it is unentered,
	 * and the sign-in by its own code is what activates it.
	 */
	public function activate(int $userId, string $hash): bool
	{
		[$intent, $storedValue] = $this->readStoredFromMaster($userId);
		if ($intent === null || !$intent->isIssuedFor($hash))
		{
			return false;
		}

		$value = $this->encode($intent->activate($this->getNow() + self::ACTIVATED_TTL_SEC));
		if ($value === null)
		{
			return false;
		}

		// a sign-in repeated within the same second has nothing left to write: the record already says this
		if ($value === $storedValue)
		{
			return true;
		}

		return $this->replace($userId, $storedValue, $value);
	}

	/**
	 * The live intent of the person, or null when there is none or it has expired. An expired record is
	 * dropped right here: it carries the chat somebody picked and the digest of a sign-in code, and
	 * nothing else ever comes back for it.
	 */
	public function read(int $userId): ?HandoffIntent
	{
		[$intent] = $this->readStored($userId);

		return $intent;
	}

	/**
	 * Hands the intent over and drops the record in the same call: an intent is delivered at most once.
	 *
	 * Reading and deleting are two statements, and the application raises the label handler at start,
	 * where a second call is ordinary - so the pair is held together by a lock of its own instead of by
	 * the order the calls happen to arrive in. A consumption that did not get the lock answers with
	 * nothing and leaves the record alone: the holder is about to hand over that very intent.
	 *
	 * The lock holds off another consumption and nothing else: the issue of a code is the hot path of the
	 * popup and takes no lock, so it lands in this pause with a record of its own. What is deleted here is
	 * therefore the record that was read and nothing else ({@see forgetIfUnchanged()}). The intent read
	 * still leaves: it was live, and the sign-in asking for it is the one it was made for. The record of
	 * the later issue stays to wait for a sign-in by its own code - deleted, it would answer the next
	 * reading that no chat was expected at all, and the form would fill the field with the chat of the
	 * previous check-in.
	 *
	 * The read is switched to the master for the same reason it is locked: a replica seconds behind
	 * would still be showing a record the holder has already deleted.
	 */
	public function consume(int $userId): ?HandoffIntent
	{
		if ($userId <= 0 || !$this->lockIntent($userId))
		{
			return null;
		}

		$this->useMasterOnly(true);

		try
		{
			$value = $this->loadValue($userId);
			if ($value === '')
			{
				return null;
			}

			$this->forgetIfUnchanged($userId, $value);

			return $this->keepLive($this->decode($value));
		}
		finally
		{
			$this->useMasterOnly(false);
			$this->unlockIntent($userId);
		}
	}

	/**
	 * Drops the record while it is still the one that was read - the deleting counterpart of the writing
	 * of the activation ({@see OptionService::deleteIfEquals()}).
	 *
	 * Every dropping in this life cycle follows a reading of the same record, and between the two an issue
	 * of a code lands without waiting for anybody ({@see remember()}): the person opened the popup again.
	 * That record belongs to a transition of its own and is none of the earlier reading's business.
	 * Nothing dropped means nothing to drop from the cache either.
	 */
	protected function forgetIfUnchanged(int $userId, string $expectedValue): bool
	{
		if ($userId <= 0 || $expectedValue === '')
		{
			return false;
		}

		$forgotten = $this->optionService->deleteIfEquals(
			$userId,
			Option::CHECKIN_HANDOFF_INTENT,
			$expectedValue,
		);

		if ($forgotten)
		{
			$this->optionProvider->invalidateCache($userId);
		}

		return $forgotten;
	}

	/**
	 * Whether the person may write to this chat. Kept behind a method of its own so that the rule of the
	 * messenger stays in the integration of the module and the life cycle here does not depend on it.
	 */
	protected function hasChatAccess(string $dialogId, int $userId): bool
	{
		return MessageService::hasDialogAccess($dialogId, $userId);
	}

	/**
	 * Switches the pool of the request to the master and back. Kept behind a method of its own for the
	 * same reason the name of the consumption is: a test has a single connection and no replica.
	 */
	protected function useMasterOnly(bool $state): void
	{
		Application::getInstance()->getConnectionPool()->useMasterOnly($state);
	}

	/**
	 * Takes the name the consumption of this person runs under. Kept behind a method of its own so that
	 * a test can play the second request without a second database connection.
	 */
	protected function lockIntent(int $userId): bool
	{
		return Application::getConnection()->lock(
			self::CONSUME_LOCK_PREFIX . $userId,
			self::CONSUME_LOCK_WAIT_SEC,
		);
	}

	protected function unlockIntent(int $userId): void
	{
		Application::getConnection()->unlock(self::CONSUME_LOCK_PREFIX . $userId);
	}

	/**
	 * The moment the code stops signing the person in, when it was issued with a lifetime of its own.
	 * The intent must not outlive the code it is bound to.
	 */
	protected function getCodeExpiry(int $userId, string $hash): ?int
	{
		$row = UserHitAuthTable::getRow([
			'select' => ['VALID_UNTIL'],
			'filter' => [
				'=USER_ID' => $userId,
				'=HASH' => $hash,
			],
		]);

		$validUntil = is_array($row) ? ($row['VALID_UNTIL'] ?? null) : null;

		return $validUntil instanceof DateTime ? $validUntil->getTimestamp() : null;
	}

	/**
	 * Whether the code still signs the person in: a code the sign-in has consumed or the next issue has
	 * deleted is no longer there. Kept behind a method of its own for the same reason the expiry of it is.
	 */
	protected function isCodeIssued(int $userId, string $hash): bool
	{
		$row = UserHitAuthTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'=USER_ID' => $userId,
				'=HASH' => $hash,
			],
		]);

		return is_array($row);
	}

	protected function getNow(): int
	{
		return time();
	}

	private function resolveExpiry(int $userId, string $hash): int
	{
		return $this->getCodeExpiry($userId, $hash) ?? ($this->getNow() + self::DEFAULT_TTL_SEC);
	}

	/**
	 * Writes the expectation of a chat where the chat itself cannot go: the person picked it on the
	 * portal and the transition happened, but the chat is out of their reach by now, or the record of it
	 * does not fit the option.
	 *
	 * The place is not left empty instead, and that is the whole point of this writing: an absent record
	 * answers the application that no chat was expected at all, and the form fills the field with the
	 * chat of the previous check-in. The bare expectation leaves the field empty. Bound to the code of
	 * this issue like any other record, it is given the span of an entered intent by the sign-in that
	 * comes by that code - the cold start of the application has to be covered here as well.
	 *
	 * The chat that was picked is known here and only undeliverable, so the entered intent of a reissue
	 * is guarded by it all the same ({@see store()}) - and so is this record once it is entered: it keeps
	 * the digest of that chat instead of the chat ({@see HandoffIntent::withoutChat()}), which is enough
	 * to recognise the reissue by and hands nothing over.
	 */
	private function rememberExpectation(
		int $userId,
		string $hash,
		string $dialogId,
		string $source,
		int $expiresAt
	): bool
	{
		return $this->store(
			$userId,
			$dialogId,
			(new HandoffIntent(HandoffIntent::digestOf($hash), $dialogId, $source, $expiresAt))->withoutChat(),
			$hash,
		);
	}

	/**
	 * Writes the record of this issue over the one left by the previous issue of a code.
	 *
	 * An entered intent is guarded from its own reissue: the popup reissues the code by a timer, and a
	 * request that left it before the scan is answered after the sign-in has already happened. Such an
	 * answer carries the chat the popup was opened with and a code nobody ever entered by, so it would
	 * replace the entered chat with an expectation without one - and the client cannot close this gap,
	 * the request is already on its way.
	 *
	 * The chat is what tells a reissue from a new transition: one popup carries one chat through every
	 * reissue, so an issue naming another chat is a person who picked another chat on the portal. A record
	 * without the chat - the expectation alone - knows the chat it was picked for by its digest
	 * ({@see HandoffIntent::isPickedFor()}), so an entered expectation is guarded the same way. That
	 * one replaces the record and waits for a sign-in of its own - until it comes, the application is
	 * given the expectation without a chat, which leaves the field empty rather than filled wrongly.
	 *
	 * A record that does not fit the option loses the chat and keeps the expectation: cut, it would read
	 * back as a chat nobody picked, and dropped, it would leave the field to the chat of the previous
	 * check-in. The digest of the chat is given up last, and only by a record that fits no other way:
	 * without it the entered expectation is no longer guarded from its reissue.
	 *
	 * The guard looks for a record another request wrote, so it reads the master ({@see readStoredFromMaster()}).
	 *
	 * The record of an issue ({@see rememberFromPayload()}) is written for a code that may already be gone:
	 * the issues of the popup overlap, and the later one deletes the code of the earlier one, issues its
	 * own and writes its record while the earlier request is still on its way. Written last, the earlier
	 * record would take the place of the code the popup is showing - the sign-in by that code would find
	 * nothing to enter, and the application would be given the expectation without the chat. The later
	 * issue deletes this code before it writes anything, so the code is looked up as the last thing before
	 * the writing: a code that is gone leaves the record alone, and what is in place is the record of the
	 * later issue or the one it is about to replace.
	 *
	 * The looking up and the writing are still two statements, and the later issue may land between them:
	 * it deletes this code and writes its record after this one has already found the code in place. So
	 * the writing replaces the record that was read and nothing else, and puts a record where there was
	 * none only while there is still none ({@see writeIfUnchanged()}). A record changed in between sends
	 * the writing back to the reading, and the rules are applied to what is there now: the later issue has
	 * deleted this code by then, and the code looked up anew leaves its record alone. A sign-in or a
	 * consumption changes the record as well without touching the code, and such a writing goes through on
	 * the reading after. A record that keeps changing is left after a few readings ({@see STORE_ATTEMPTS})
	 * as it stands - the writing answers false, like any other writing that did not happen.
	 */
	private function store(int $userId, string $pickedDialogId, HandoffIntent $intent, string $issuedHash = ''): bool
	{
		$now = $this->getNow();
		if ($userId <= 0 || $pickedDialogId === '' || $intent->hashDigest === '' || $intent->isExpired($now))
		{
			return false;
		}

		$value = $this->encode($intent)
			?? $this->encode($intent->withoutChat())
			?? $this->encode($intent->withoutChatDigest())
		;
		if ($value === null)
		{
			return false;
		}

		for ($attempt = 0; $attempt < self::STORE_ATTEMPTS; $attempt++)
		{
			[$current, $storedValue] = $this->readStoredFromMaster($userId);
			if ($current !== null && $current->activated && $current->isPickedFor($pickedDialogId))
			{
				return false;
			}

			if ($issuedHash !== '' && !$this->isCodeIssuedOnMaster($userId, $issuedHash))
			{
				return false;
			}

			// the record already says this, and a replace of a value by itself is not told from a lost race
			if ($value === $storedValue)
			{
				return true;
			}

			if ($this->writeIfUnchanged($userId, $storedValue, $value))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The writing of an issue: the record gives way only while it is the one that was read, and the place
	 * that was read empty is taken only while it is still empty. Nothing written means nothing to drop
	 * from the cache either.
	 */
	private function writeIfUnchanged(int $userId, string $expectedValue, string $value): bool
	{
		if ($expectedValue !== '')
		{
			return $this->replace($userId, $expectedValue, $value);
		}

		$inserted = $this->optionService->insertIfAbsent($userId, Option::CHECKIN_HANDOFF_INTENT, $value);

		if ($inserted)
		{
			$this->optionProvider->invalidateCache($userId);
		}

		return $inserted;
	}

	/**
	 * Whether the code is still issued, as the master knows it: the deleting of the code is done by another
	 * request, and a replica behind by that much would still show a code that is already gone.
	 */
	private function isCodeIssuedOnMaster(int $userId, string $hash): bool
	{
		$this->useMasterOnly(true);

		try
		{
			return $this->isCodeIssued($userId, $hash);
		}
		finally
		{
			$this->useMasterOnly(false);
		}
	}

	/**
	 * Whether the chat may travel with the intent. The membership is asked of another module, and an
	 * answer that never came is not a membership: a failure carried outside this method would leave the
	 * intent of the previous transition in place ({@see onDeeplinkPayloadIssued()}), while the bare
	 * expectation written in its stead leaves the field of the form empty.
	 */
	private function isChatDeliverable(string $dialogId, int $userId, string $hash): bool
	{
		try
		{
			return $this->hasChatAccess($dialogId, $userId);
		}
		catch (\Throwable $error)
		{
			self::report('isChatDeliverable', $error, $hash);

			return false;
		}
	}

	/**
	 * The intent as the master holds it. Every reading taken this way looks for a record another request
	 * wrote: the issue of a code writes what the sign-in then activates, and the sign-in leaves what a
	 * later issue must not overwrite. A replica seconds behind would answer with the record as it stood
	 * before that other request, or with nothing at all. The pool is handed back whatever the reading does.
	 *
	 * The record itself comes back as well: a writing that replaces the record only while it is untouched
	 * has to name the very value the reading met, and a second reading would already be another moment.
	 *
	 * @return array{0: ?HandoffIntent, 1: string}
	 */
	private function readStoredFromMaster(int $userId): array
	{
		$this->useMasterOnly(true);

		try
		{
			return $this->readStored($userId);
		}
		finally
		{
			$this->useMasterOnly(false);
		}
	}

	/**
	 * The reading of {@see read()} together with the record it was decoded from.
	 *
	 * @return array{0: ?HandoffIntent, 1: string}
	 */
	private function readStored(int $userId): array
	{
		if ($userId <= 0)
		{
			return [null, ''];
		}

		$value = $this->loadValue($userId);
		$intent = $this->decode($value);
		$live = $this->keepLive($intent);

		// the record dropped here is no longer the one in place: what is in place now is nothing
		if ($intent !== null && $live === null && $this->forgetIfUnchanged($userId, $value))
		{
			$value = '';
		}

		return [$live, $value];
	}

	private function keepLive(?HandoffIntent $intent): ?HandoffIntent
	{
		return $intent !== null && !$intent->isExpired($this->getNow()) ? $intent : null;
	}

	/**
	 * The writing of the activation: the record gives way only while it is the one that was read. Nothing
	 * changed means nothing to drop from the cache either.
	 */
	private function replace(int $userId, string $expectedValue, string $value): bool
	{
		$replaced = $this->optionService->replaceIfEquals(
			$userId,
			Option::CHECKIN_HANDOFF_INTENT,
			$expectedValue,
			$value,
		);

		if ($replaced)
		{
			$this->optionProvider->invalidateCache($userId);
		}

		return $replaced;
	}

	/**
	 * The record as it is stored. Kept behind a method of its own so that a test can make the storage
	 * fail under the reading.
	 */
	protected function loadValue(int $userId): string
	{
		$row = OptionTable::query()
			->setSelect(['VALUE'])
			->where('USER_ID', $userId)
			->where('NAME', Option::CHECKIN_HANDOFF_INTENT->value)
			->setLimit(1)
			->exec()
			->fetch()
		;

		return is_array($row) ? (string)($row['VALUE'] ?? '') : '';
	}

	/**
	 * A record that does not fit the option value is refused rather than cut: a truncated intent would
	 * read back as a chat nobody picked. What takes its place is the expectation alone ({@see store()}).
	 */
	private function encode(HandoffIntent $intent): ?string
	{
		$data = [
			'hashDigest' => $intent->hashDigest,
			'dialogId' => $intent->dialogId,
			'source' => $intent->source,
			'expiresAt' => $intent->expiresAt,
			'activated' => $intent->activated,
		];

		// only the expectation alone needs the digest of its chat: a record with the chat names it
		if ($intent->dialogId === '' && $intent->chatDigest !== '')
		{
			$data['chatDigest'] = $intent->chatDigest;
		}

		$value = json_encode($data);

		if (!is_string($value) || mb_strlen($value) > self::MAX_VALUE_LENGTH)
		{
			return null;
		}

		return $value;
	}

	private function decode(string $value): ?HandoffIntent
	{
		if ($value === '')
		{
			return null;
		}

		$data = json_decode($value, true);
		if (!is_array($data))
		{
			return null;
		}

		$hashDigest = (string)($data['hashDigest'] ?? '');
		$dialogId = $data['dialogId'] ?? null;
		$expiresAt = (int)($data['expiresAt'] ?? 0);

		// an empty chat is the bare expectation, a missing one is a record this service never wrote
		if ($hashDigest === '' || !is_string($dialogId) || $expiresAt <= 0)
		{
			return null;
		}

		return new HandoffIntent(
			$hashDigest,
			$dialogId,
			(string)($data['source'] ?? self::SOURCE_CHAT_ATTACH_QR),
			$expiresAt,
			(bool)($data['activated'] ?? false),
			$dialogId === '' ? (string)($data['chatDigest'] ?? '') : '',
		);
	}

	/**
	 * The code signs a person in, and the message of an exception may quote the stored record, so it is
	 * struck out of the journal.
	 */
	private static function report(string $operation, \Throwable $error, string $hash): void
	{
		$message = $error->getMessage();
		if ($hash !== '')
		{
			$message = str_replace($hash, '***', $message);
		}

		AddMessage2Log(self::class . '::' . $operation . ' error: ' . $message, 'stafftrack');
	}
}
