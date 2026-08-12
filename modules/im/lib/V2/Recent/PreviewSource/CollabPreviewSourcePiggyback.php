<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\PreviewSource;

use Bitrix\Im\V2\Message;

/**
 * Carries the preview-source pointer onto the collab recent row inside the {@see \Bitrix\Im\Recent::raiseChat}
 * batch MERGE. The pointer is uniform across all raised recipients because the MERGE update set is shared and
 * cannot hold per-row values dialect-agnostically; per-user refinement is converged later by the read recompute.
 *
 * On the direct send shape the carrier may also hold a runtime-only {@see Message} hint so the realtime push can
 * build the preview from the just-sent object without re-reading the pointer. The hint never participates in the
 * MERGE; the roll-up shape never carries one.
 */
final class CollabPreviewSourcePiggyback
{
	private function __construct(
		private readonly int $collabChatId,
		private readonly int $sourceChatId,
		private readonly int $messageId,
		private readonly ?Message $sourceMessageHint = null,
	) {}

	/**
	 * Pass $sourceMessage (runtime-only hint) ONLY when the pointer equals exactly this new message.
	 */
	public static function forNewMessage(
		int $collabChatId,
		int $sourceChatId,
		int $newMessageId,
		?Message $sourceMessage = null,
	): self
	{
		return new self($collabChatId, $sourceChatId, $newMessageId, $sourceMessage);
	}

	public static function forChannelRollup(int $collabChatId, int $channelChatId, int $channelLastOwnMessageId): self
	{
		return new self($collabChatId, $channelChatId, $channelLastOwnMessageId);
	}

	public function appliesTo(int $chatId): bool
	{
		return $chatId === $this->collabChatId;
	}

	public function hasPointer(): bool
	{
		return $this->sourceChatId > 0 && $this->messageId > 0;
	}

	/**
	 * @return array{PREVIEW_SOURCE_CID:int,PREVIEW_SOURCE_MID:int}|null
	 */
	public function getColumns(): ?array
	{
		if (!$this->hasPointer())
		{
			return null;
		}

		return [
			'PREVIEW_SOURCE_CID' => $this->sourceChatId,
			'PREVIEW_SOURCE_MID' => $this->messageId,
		];
	}

	/**
	 * Guards against a stale hint: usable only when it describes the carried pointer (same message id).
	 */
	public function hasObjectHint(): bool
	{
		return $this->hasPointer()
			&& $this->sourceMessageHint !== null
			&& (int)$this->sourceMessageHint->getId() === $this->messageId;
	}

	public function getPreviewSource(): PreviewSource
	{
		if ($this->sourceChatId === $this->collabChatId)
		{
			return PreviewSource::mainChat($this->collabChatId, $this->messageId);
		}

		return new PreviewSource(
			sourceChatId: $this->sourceChatId,
			messageId: $this->messageId,
			isMainChat: false,
		);
	}

	public function getSourceMessageHint(): ?Message
	{
		return $this->sourceMessageHint;
	}
}
