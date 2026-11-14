<?php

namespace Bitrix\StaffTrack\Internal\Entity;

/**
 * The chat a person picked on the portal before the transition code was shown, kept on the server until
 * the application asks for it.
 *
 * The record carries two parts of different reliability. The chat travels only with the code the intent
 * was issued for: {@see $activated} tells that the sign-in happened by that very code. The expectation
 * of a chat outlives what the chat itself does not - a reissued code, a sign-in by another one, an
 * activation that never came - because the popup reissues the code while the application is still
 * starting, and a pending expectation read as "no chat was expected" would let the form fill the field
 * with the chat of the previous check-in. Expiry ends both parts at once.
 *
 * The expectation also stands alone from the very start ({@see withoutChat()}): a transition happened,
 * but its chat cannot travel with it - the person left the chat, the record of it did not fit. What the
 * place must not keep then is the intent of the previous transition, chat and all.
 *
 * The code the intent is bound to signs a person in, and the record is stored among the ordinary
 * settings of the domain, so what the intent keeps is a digest of that code: telling one code from
 * another is the only thing ever asked of it.
 *
 * The expectation alone keeps a digest of the chat that was picked ({@see $chatDigest}) for the same
 * reason: the chat is not to travel, but the late reissue of the popup it was picked in still has to be
 * told from a new transition ({@see isPickedFor()}). The digest is never handed over.
 *
 * @see \Bitrix\StaffTrack\Internal\Service\CheckIn\HandoffIntentService
 */
class HandoffIntent
{
	public function __construct(
		public readonly string $hashDigest,
		public readonly string $dialogId,
		public readonly string $source,
		public readonly int $expiresAt,
		public readonly bool $activated = false,
		public readonly string $chatDigest = '',
	)
	{
	}

	public static function digestOf(string $hash): string
	{
		return $hash === '' ? '' : hash('sha256', $hash);
	}

	public function isExpired(int $now): bool
	{
		return $this->expiresAt <= $now;
	}

	public function isIssuedFor(string $hash): bool
	{
		return $hash !== '' && hash_equals($this->hashDigest, self::digestOf($hash));
	}

	/**
	 * Whether the record was made for this chat. A record with the chat names it, the expectation alone
	 * knows it by the digest kept when the chat stayed behind ({@see withoutChat()}).
	 */
	public function isPickedFor(string $dialogId): bool
	{
		if ($dialogId === '')
		{
			return false;
		}

		if ($this->dialogId !== '')
		{
			return $this->dialogId === $dialogId;
		}

		return $this->chatDigest !== '' && hash_equals($this->chatDigest, self::digestOf($dialogId));
	}

	/**
	 * The sign-in came by the code of this intent, and from here on the intent is bound to nothing that
	 * expires: the code has just been consumed and deleted, so outliving it costs no one anything. The
	 * expiry is moved to cover what the code never could - the cold start of the application, which
	 * happens after the sign-in and not before it. An expiry already further away is left alone.
	 */
	public function activate(int $expiresAt): self
	{
		return new self(
			$this->hashDigest,
			$this->dialogId,
			$this->source,
			max($this->expiresAt, $expiresAt),
			true,
			$this->chatDigest,
		);
	}

	/**
	 * The same record with the expectation alone: the transition happened, and the chat it was made for
	 * stays behind. The code is kept, so the sign-in by it gives this record the span of an entered
	 * intent as well - the cold start of the application has to be covered either way. The chat itself is
	 * kept as a digest, so that a reissue of it is still recognised ({@see isPickedFor()}).
	 */
	public function withoutChat(): self
	{
		return new self(
			$this->hashDigest,
			'',
			$this->source,
			$this->expiresAt,
			$this->activated,
			$this->dialogId !== '' ? self::digestOf($this->dialogId) : $this->chatDigest,
		);
	}

	/**
	 * The expectation that no longer recognises the chat it was made for - the last thing a record gives
	 * up to fit the option.
	 */
	public function withoutChatDigest(): self
	{
		return new self($this->hashDigest, '', $this->source, $this->expiresAt, $this->activated);
	}

	/**
	 * The chat is handed over only to the sign-in that came by the code of this intent; every other
	 * reader gets the expectation alone.
	 */
	public function getDeliverableDialogId(): ?string
	{
		return $this->activated && $this->dialogId !== '' ? $this->dialogId : null;
	}

	public function getDeliverableSource(): ?string
	{
		return $this->getDeliverableDialogId() === null ? null : $this->source;
	}
}
