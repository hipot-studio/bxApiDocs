<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search;

/**
 * What a deletion of the index walks: a chat, the part of a chat standing below a history boundary,
 * one post of a channel together with the comments addressed by it, or everything a user of the
 * portal ever authored.
 *
 * It carries no cursor by construction - the walk consumes what it reads - so the address alone says
 * what is left of the deletion, and a unit of work that ran out of its budget hands it over as it is.
 */
final class DeletionAddress
{
	public const CHAT = 'chat';
	public const POST = 'post';
	public const AUTHOR = 'author';
	public const HISTORY = 'history';

	private const KEY_PREFIX = 'delete';

	private function __construct(
		public readonly string $type,
		public readonly int $id,
		public readonly string $postKey,
		public readonly string $boundaryKey = '',
	)
	{}

	public static function ofChat(int $chatId): ?self
	{
		return $chatId > 0 ? new self(self::CHAT, $chatId, '') : null;
	}

	public static function ofPost(int $channelChatId, string $postKey): ?self
	{
		return $channelChatId > 0 && $postKey !== '' ? new self(self::POST, $channelChatId, $postKey) : null;
	}

	public static function ofAuthor(int $userId): ?self
	{
		return $userId > 0 ? new self(self::AUTHOR, $userId, '') : null;
	}

	/**
	 * Entries of a chat standing below a history boundary: the messages a member cleared away and that
	 * were taken out of the database with them.
	 */
	public static function ofHistoryBelow(int $chatId, string $boundaryKey): ?self
	{
		return $chatId > 0 && $boundaryKey !== '' ? new self(self::HISTORY, $chatId, '', $boundaryKey) : null;
	}

	/**
	 * @param array<string, mixed> $address
	 */
	public static function fromArray(array $address): ?self
	{
		$id = (int)($address['id'] ?? 0);

		return match ((string)($address['type'] ?? ''))
		{
			self::CHAT => self::ofChat($id),
			self::POST => self::ofPost($id, (string)($address['postKey'] ?? '')),
			self::AUTHOR => self::ofAuthor($id),
			self::HISTORY => self::ofHistoryBelow($id, (string)($address['boundaryKey'] ?? '')),
			default => null,
		};
	}

	/**
	 * @return array<string, int|string>
	 */
	public function toArray(): array
	{
		$address = ['type' => $this->type, 'id' => $this->id];
		if ($this->postKey !== '')
		{
			$address['postKey'] = $this->postKey;
		}

		if ($this->boundaryKey !== '')
		{
			$address['boundaryKey'] = $this->boundaryKey;
		}

		return $address;
	}

	/**
	 * Identity of the address in the queue of the walk: the same deletion queued twice is one element,
	 * and a walk that empties the address takes off everything under it either way.
	 */
	public function getKey(): string
	{
		$key = self::KEY_PREFIX . ':' . $this->type . ':' . $this->id;
		$qualifier = $this->postKey === '' ? $this->boundaryKey : $this->postKey;

		return $qualifier === '' ? $key : $key . ':' . $qualifier;
	}
}
