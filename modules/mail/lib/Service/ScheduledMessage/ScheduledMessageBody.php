<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\ScheduledMessage;

use Bitrix\Mail\Helper\Message;

/**
 * The body of a snapshot lives twice: it goes back to the form of its author and it leaves the snapshot for
 * a letter or for a draft. Stored, it is already sanitized, so no reader of the table meets the html the
 * browser sent; on the way out to a letter it is sanitized once more and stripped of the marks the form
 * leaves in it for itself, so the boundary of the sending holds on its own and not on the source of the row.
 */
final class ScheduledMessageBody
{
	/** Marks of the form: the blocks of the body and the signature chosen for it (`scheduled-body.ts`). */
	private const MARKER_CLASS_PATTERN = '/\s*\bmail-compose-scheduled-[\w-]+\b/i';
	private const CLASS_ATTRIBUTE_PATTERN = '/\s+class="([^"]*)"/i';
	private const INLINE_IMAGE_MARK_PATTERN = '/https?:\/\/bxacid:(n?\d+)/i';
	private const INLINE_IMAGE_REFERENCE_PATTERN = '/(?:https?:\/\/)?bxacid:n?(\d+)/i';

	public static function sanitize(string $body): string
	{
		if ($body === '')
		{
			return '';
		}

		$sanitized = Message::sanitizeHtml($body);

		// The sanitizer completes the mark of an inline image to an absolute url; the editor reads it bare.
		return preg_replace(self::INLINE_IMAGE_MARK_PATTERN, 'bxacid:$1', $sanitized) ?? $sanitized;
	}

	/**
	 * The body of an outgoing letter. Sanitizing here repeats the work of the write on purpose: a row written
	 * around the service — an older one, a hand-made one — would otherwise hand its html to the recipient,
	 * and the sanitizer is idempotent, so a body already cleaned passes through unchanged.
	 *
	 * @param array<int, string> $contentIdBySourceObjectId Content-ID of every attachment by its source object.
	 */
	public static function forOutgoing(string $body, array $contentIdBySourceObjectId = []): string
	{
		return self::linkInlineImages(
			self::stripFormMarks(self::sanitize($body)),
			$contentIdBySourceObjectId,
		);
	}

	/**
	 * An image pasted into the editor is marked in the body by the Disk object it was uploaded as, and the
	 * letter carries that image as a part of its own: the mark becomes a reference to the part. A mark whose
	 * object the letter carries no file for is left alone — the recipient sees a broken image either way, and
	 * a body of a mailbox the marks of another portal reached must not be rewritten by guesswork.
	 *
	 * @param array<int, string> $contentIdBySourceObjectId
	 */
	public static function linkInlineImages(string $body, array $contentIdBySourceObjectId): string
	{
		if ($contentIdBySourceObjectId === [])
		{
			return $body;
		}

		return preg_replace_callback(
			self::INLINE_IMAGE_REFERENCE_PATTERN,
			static fn (array $matches): string => isset($contentIdBySourceObjectId[(int)$matches[1]])
				? 'cid:' . $contentIdBySourceObjectId[(int)$matches[1]]
				: $matches[0],
			$body,
		) ?? $body;
	}

	/** The marks name the blocks of the form; a body that leaves the snapshot has no reader for them. */
	public static function stripFormMarks(string $body): string
	{
		$cleared = preg_replace(self::MARKER_CLASS_PATTERN, '', $body) ?? $body;

		return self::normalizeClassAttributes($cleared);
	}

	/**
	 * A mark taken from the head of a class list leaves behind the space that stood after it, and the sanitizer
	 * of the next pass writes the same list without that space: the boundary would then answer a body it had
	 * already cleaned with other bytes. A list left empty loses the attribute altogether.
	 */
	private static function normalizeClassAttributes(string $body): string
	{
		return preg_replace_callback(
			self::CLASS_ATTRIBUTE_PATTERN,
			static function (array $match): string {
				$classes = trim((string)preg_replace('/\s+/', ' ', $match[1]));

				return $classes === '' ? '' : sprintf(' class="%s"', $classes);
			},
			$body,
		) ?? $body;
	}
}
