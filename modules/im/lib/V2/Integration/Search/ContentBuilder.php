<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search;

use Bitrix\Im\Text;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;

/**
 * Builds the index entry of a single message. The field set is the backward compatibility contract
 * with the records the corporate portal already holds, with two product changes: file names stay out
 * of the body, and a comment is addressed by its parent post instead of by itself.
 */
class ContentBuilder
{
	private const AUTHOR_ENTITY_TYPE_ID = 'im_user';

	/** The only format CSearch::Index parses DATE_CHANGE with. */
	private const DATE_FORMAT = 'd.m.Y H:i:s';

	/**
	 * Sortable message key of PARAM2: the fixed width is what makes the '>=PARAM2' filter of a
	 * permission operation compare keys as history boundaries and not as arbitrary strings.
	 */
	private const MESSAGE_KEY_FORMAT = 'M%019d';

	public function __construct(
		private readonly PermissionBuilder $permissionBuilder,
	)
	{}

	/**
	 * @return array Fields of the index entry; empty when the message yields no entry at all.
	 */
	public function build(Message $message, Chat $chat): array
	{
		$messageId = $message->getMessageId();
		$siteId = SiteHelper::getSiteId();
		if ($messageId === null || $siteId === null)
		{
			return [];
		}

		$authorId = $message->getAuthorId();

		return [
			'PARAM1' => $this->getAddressedChatId($chat),
			'PARAM2' => self::buildMessageKey($this->getAccessKeyId($chat, $messageId)),

			'USER_ID' => $authorId,
			'ENTITY_ID' => $authorId,
			'ENTITY_TYPE_ID' => self::AUTHOR_ENTITY_TYPE_ID,

			'SITE_ID' => [$siteId => $this->buildUrl($chat, $messageId)],
			'DATE_CHANGE' => $this->getDateChange($message),
			'PERMISSIONS' => $this->permissionBuilder->build($chat, $messageId),
			'BODY' => $this->buildBody($message),
			'INDEX_TITLE' => false,
		];
	}

	/**
	 * Single owner of the PARAM2 format: permission operations address history boundaries by the very
	 * same key, so a width that drifts apart here silently breaks their range filters.
	 */
	public static function buildMessageKey(int $messageId): string
	{
		return sprintf(self::MESSAGE_KEY_FORMAT, $messageId);
	}

	/**
	 * Single owner of the searchable text of a message: the body of an entry is built of it, and the
	 * hygiene registry asks this very method whether the message has anything to be found by.
	 *
	 * Text::removeBbCodes() answers a text that cleans down to nothing with the 'message deleted'
	 * phrase, which is a caption of the interface: an entry carrying it would answer a search for a
	 * deleted message. It is recognised by cleaning an empty text with the same call and not by its
	 * wording, which belongs to the language of the portal.
	 */
	public static function buildSearchableText(string $text): string
	{
		$cleaned = (string)Text::removeBbCodes($text);

		return $cleaned === (string)Text::removeBbCodes('') ? '' : $cleaned;
	}

	private function getAddressedChatId(Chat $chat): int
	{
		if ($chat instanceof Chat\CommentChat)
		{
			return $chat->getParentChatId() ?? 0;
		}

		return $chat->getId() ?? 0;
	}

	private function getAccessKeyId(Chat $chat, int $messageId): int
	{
		if ($chat instanceof Chat\CommentChat)
		{
			return $chat->getParentMessageId();
		}

		return $messageId;
	}

	/**
	 * The URL string keeps the meaning it has in the already collected records: the chat of the message
	 * itself, not the one the entry is addressed by. Comments carry the parent pair on top of it.
	 */
	private function buildUrl(Chat $chat, int $messageId): string
	{
		$url = '=CHAT_ID=' . ($chat->getId() ?? 0) . '&ID=' . $messageId . '&MESSAGE_TYPE=' . $chat->getType();

		$parentChatId = $chat->getParentChatId() ?? 0;
		if ($parentChatId > 0)
		{
			$url .= '&CHAT_PARENT_ID=' . $parentChatId;
		}

		$parentMessageId = $chat->getParentMessageId();
		if ($parentMessageId > 0)
		{
			$url .= '&CHAT_PARENT_MID=' . $parentMessageId;
		}

		return $url;
	}

	/**
	 * Always the creation date, on the sending path and on the editing path alike: an edit rewrites the
	 * whole entry, so a date taken from anywhere else would move the message in the 'newest first' order.
	 */
	private function getDateChange(Message $message): string
	{
		$dateCreate = $message->getDateCreate() ?? $message->getDefaultDateCreate();

		return $dateCreate->format(self::DATE_FORMAT);
	}

	private function buildBody(Message $message): string
	{
		$body = self::buildSearchableText($message->getMessage() ?? '');

		$params = $message->getParams();
		if (!$params->isSet(Message\Params::ATTACH))
		{
			return $body;
		}

		foreach ($params->get(Message\Params::ATTACH)->getValue() as $attach)
		{
			foreach (\CIMMessageParamAttach::GetTextForIndex($attach) as $text)
			{
				$body .= ' ' . $text;
			}
		}

		return trim($body);
	}
}
