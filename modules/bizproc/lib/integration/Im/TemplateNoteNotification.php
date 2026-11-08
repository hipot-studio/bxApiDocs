<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Integration\Im;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;

/**
 * The personal notification of being mentioned in a note of a template scheme. What is discussed stays
 * in the discussion: the notification carries the person it comes from and the link to the note, never
 * the text of the message, and the link is assembled here out of two numbers.
 *
 * The tag groups the notifications of one note, so that a person mentioned there again keeps a single
 * notification instead of a pile of them. The sub-tag names the pair of the note and its recipient and
 * is what a revoke is found by: a mention taken away by an edit or gone with a deleted message must not
 * leave a live link behind.
 *
 * The messenger is an optional module. Every method below expects {@see self::isAvailable()} to have
 * answered true - the caller asks once per operation instead of once per recipient.
 */
class TemplateNoteNotification
{
	/**
	 * The type of the notification in the settings of a person ({@see \Bitrix\Bizproc\Integration\NotifySchema}).
	 */
	public const NOTIFY_EVENT = 'template_note_mention';

	private const NOTIFY_MODULE = 'bizproc';
	private const TAG_PREFIX = 'BIZPROC|TEMPLATE_NOTE|';
	private const EDITOR_PATH = '/bizprocdesigner/editor/';

	public function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	/**
	 * @return bool whether the notification has been created
	 */
	public function notifyMention(int $templateId, int $noteId, int $authorId, int $recipientId): bool
	{
		$url = self::noteUrl($templateId, $noteId);

		return $this->send([
			'MESSAGE_TYPE' => IM_MESSAGE_SYSTEM,
			'NOTIFY_TYPE' => IM_NOTIFY_FROM,
			'FROM_USER_ID' => $authorId,
			'TO_USER_ID' => $recipientId,
			'NOTIFY_MODULE' => self::NOTIFY_MODULE,
			'NOTIFY_EVENT' => self::NOTIFY_EVENT,
			'NOTIFY_TAG' => self::tag($noteId),
			'NOTIFY_SUB_TAG' => self::subTag($noteId, $recipientId),
			// the messenger resolves the closure in the language of the recipient
			'MESSAGE' => static fn(?string $languageId = null): string => Loc::getMessage(
				'BIZPROC_TEMPLATE_NOTE_NOTIFY_MENTION',
				['#LINK#' => $url],
				$languageId,
			),
		]);
	}

	public function revokeMention(int $noteId, int $recipientId): void
	{
		$this->deleteBySubTag(self::subTag($noteId, $recipientId));
	}

	protected function send(array $fields): bool
	{
		return \CIMNotify::Add($fields) !== false;
	}

	protected function deleteBySubTag(string $subTag): void
	{
		\CIMNotify::DeleteBySubTag($subTag);
	}

	private static function tag(int $noteId): string
	{
		return self::TAG_PREFIX . $noteId;
	}

	/**
	 * The notification and its revoke have to produce the same string, or the revoke finds nothing.
	 */
	private static function subTag(int $noteId, int $recipientId): string
	{
		return self::tag($noteId) . '|' . $recipientId;
	}

	private static function noteUrl(int $templateId, int $noteId): string
	{
		$uri = new Uri(self::EDITOR_PATH);
		$uri->addParams(['ID' => $templateId, 'noteId' => $noteId]);

		return $uri->getUri();
	}
}
