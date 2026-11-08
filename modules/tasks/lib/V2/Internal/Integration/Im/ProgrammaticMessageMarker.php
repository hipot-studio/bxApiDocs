<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im;

use Bitrix\Im\V2\Message;

/**
 * Marks messages this module sends programmatically. Every non-interactive task
 * chat send goes through {@see MessageSender}, while a genuine UI comment is a
 * native IM send that never touches this module - and `AfterSendMessageEvent`
 * cannot tell the two apart, since both carry a real, non-system author.
 *
 * The mark rides on the Message instance itself, which survives from the send to
 * the after-send handler within one hit. The store is static, so the mark holds
 * across every resolution of this service; the WeakMap releases the entry with
 * the message.
 */
final class ProgrammaticMessageMarker
{
	/** @var \WeakMap<Message, true>|null */
	private static ?\WeakMap $marks = null;

	public function markProgrammatic(Message $message): void
	{
		self::marks()[$message] = true;
	}

	public function isProgrammatic(Message $message): bool
	{
		return isset(self::marks()[$message]);
	}

	private static function marks(): \WeakMap
	{
		return self::$marks ??= new \WeakMap();
	}
}
