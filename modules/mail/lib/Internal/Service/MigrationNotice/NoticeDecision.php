<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MigrationNotice;

/**
 * What to show a person on this surface right now (DTO-01): the message, the mailbox it is about,
 * its texts and its buttons. The decision is computed and never stored.
 *
 * The clients render what they are given and know no rule of their own, so the texts travel with
 * the decision: the requirement "the texts are the same on both platforms" costs one set of phrases
 * on the server instead of a set in the web module and another in the mobile one.
 */
final class NoticeDecision
{
	/**
	 * The buttons a decision may carry (DTO-02). The value is what reaches the client; which button
	 * belongs to which message is decided by {@see NoticeDecisionService}.
	 */
	public const TRANSFER_MAIL = 'transfer_mail';
	public const REMIND_LATER = 'remind_later';
	public const DETAILS = 'details';
	public const CREATE_COMPANY_MAIL = 'create_company_mail';
	public const CONNECT_EXTERNAL = 'connect_external';
	public const CONNECT_B24_MAIL = 'connect_b24_mail';

	/**
	 * @param array<int, array{id: string, label: string, url: string}> $buttons Ordered as rendered.
	 * @param string $reactionToken What the client returns with a reaction so that the reaction is
	 *     counted for this very message and this very mailbox.
	 */
	public function __construct(
		public readonly NoticeCode $code,
		public readonly ?int $mailboxId = null,
		public readonly string $title = '',
		public readonly string $text = '',
		public readonly array $buttons = [],
		public readonly string $dismissHint = '',
		public readonly string $reactionToken = '',
	)
	{
	}

	public static function none(): self
	{
		return new self(NoticeCode::None);
	}

	/** @return array<string, mixed> DTO-01 as it reaches the web and the mobile client. */
	public function toArray(): array
	{
		return [
			'code' => $this->code->value,
			'mailboxId' => $this->mailboxId,
			'title' => $this->title,
			'text' => $this->text,
			'buttons' => $this->buttons,
			'dismissHint' => $this->dismissHint,
			'reactionToken' => $this->reactionToken,
		];
	}
}
