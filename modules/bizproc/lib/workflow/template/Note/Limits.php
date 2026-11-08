<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Note;

/**
 * The limits of a note discussion, kept in one place because three sides read them: the service checks
 * them, the list answer carries them to the editor, and the tests assert against them.
 *
 * A note is over when it is gone together with its messages, so the limit on notes of a template is a
 * count of the rows that exist.
 */
final class Limits
{
	public const MESSAGE_LENGTH = 4000;
	public const NOTES_PER_TEMPLATE = 200;
	public const MESSAGES_PER_NOTE = 500;
	public const MENTIONS_PER_MESSAGE = 20;

	/**
	 * The keys are the names the editor reads (API-01) and are part of the answer contract.
	 */
	public static function toArray(): array
	{
		return [
			'messageLength' => self::MESSAGE_LENGTH,
			'notesPerTemplate' => self::NOTES_PER_TEMPLATE,
			'messagesPerNote' => self::MESSAGES_PER_NOTE,
			'mentionsPerMessage' => self::MENTIONS_PER_MESSAGE,
		];
	}
}
