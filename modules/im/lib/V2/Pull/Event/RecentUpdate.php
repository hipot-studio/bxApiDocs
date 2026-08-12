<?php

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Pull\Dto\Diff;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Pull\RecentPreviewPullTrait;
use Bitrix\Im\V2\Recent\PreviewSource\CollabPreviewSourcePiggyback;
use Bitrix\Im\V2\Recent\PreviewSource\PreviewSource;
use Bitrix\Main\Type\DateTime;

class RecentUpdate extends BaseChatEvent
{
	use RecentPreviewPullTrait;

	protected array $recipients;
	protected DateTime $lastActivity;

	/** @var array<int,PreviewSource>|null */
	private ?array $collabPreviewSources = null;

	private ?CollabPreviewSourcePiggyback $previewHint = null;

	private ?array $hintCollabPreviewDiff = null;
	private bool $hintCollabPreviewDiffResolved = false;

	/** @var array<string,array> */
	private array $collabPreviewDiffByPointer = [];

	private ?array $mainChatMessageDiff = null;
	private bool $mainChatMessageDiffResolved = false;

	/**
	 * @param int[] $recipients
	 */
	public function __construct(
		Chat $chat,
		array $recipients,
		?DateTime $lastActivity = null,
		?CollabPreviewSourcePiggyback $previewHint = null,
	)
	{
		$this->recipients = array_map('intval', $recipients);
		$this->lastActivity = $lastActivity ?? new DateTime();
		// Only keep a usable, collab-matching object hint.
		if (
			$previewHint !== null
			&& $previewHint->hasObjectHint()
			&& $previewHint->appliesTo((int)$chat->getId())
		)
		{
			$this->previewHint = $previewHint;
		}

		parent::__construct($chat);
	}

	protected function getRecipients(): array
	{
		return $this->recipients;
	}

	protected function getBasePullParamsInternal(): array
	{
		return $this->buildBasePreviewParams($this->lastActivity);
	}

	/**
	 * A collab row shows a per-user preview message (a child chat's message or the collab's own last one),
	 * so the message is resolved per recipient in getDiffByUser() and must be kept out of the shared base.
	 */
	private function hasCollabPreview(): bool
	{
		return $this->chat instanceof CollabChat;
	}

	protected function buildBasePreviewParams(?DateTime $lastActivity): array
	{
		$params = $this->getBaseRecentPreviewParams($this->chat, $this->chat->getLastMessage(), $lastActivity);

		if ($this->hasCollabPreview())
		{
			// The collab preview message is resolved per-user and provided in full in getDiffByUser().
			// It must not stay in the shared base: applyDiff() deep-merges `message`, so the base message's
			// params (e.g. FILE_ID of the collab's own last message) would leak onto a child preview message
			// that has none — a photo sent to the project would stick to a child task system message.
			unset($params['message']);
		}

		return $params;
	}

	protected function getDiffByUser(int $userId): Diff
	{
		$params = $this->getRecentPreviewUserDiffParams($this->chat, $userId);

		if ($this->hasCollabPreview())
		{
			// Always provide the full per-user preview message here (a child source message, or the collab's
			// own last message). getBasePullParamsInternal() intentionally keeps `message` out of the base,
			// so this is a full replacement and no cross-chat params leak.
			// resolveCollabPreviewForUser() carries a message only for a child source; for the main-chat
			// source it returns [] (and a child with a vanished source message returns only `chats`), so
			// whenever there is no message we fall back to the collab's own last message.
			$collabPreview = $this->resolveCollabPreviewForUser($userId);
			if ($collabPreview === null || !isset($collabPreview['message']))
			{
				$collabPreview = $this->getMainChatMessageDiff();
			}
			$params = array_merge($params, $collabPreview);
		}

		return new Diff($userId, $params);
	}

	/**
	 * @return array|null the collab preview override to merge over the base diff, or null when there is none
	 */
	private function resolveCollabPreviewForUser(int $userId): ?array
	{
		if ($this->previewHint !== null)
		{
			return $this->getHintCollabPreviewDiff();
		}

		$previewSource = $this->getCollabPreviewSources()[$userId] ?? null;
		if ($previewSource === null)
		{
			return null;
		}

		$pointerKey = $previewSource->sourceChatId . '_' . $previewSource->messageId;
		$this->collabPreviewDiffByPointer[$pointerKey] ??=
			$this->getCollabPreviewUserDiffParams($this->chat, $previewSource);

		return $this->collabPreviewDiffByPointer[$pointerKey];
	}

	/**
	 * The collab's own last-message payload (message + users + files), used as the per-user preview when
	 * the user's preview source is the collab main chat. Memoized: identical for every such recipient.
	 */
	private function getMainChatMessageDiff(): array
	{
		if (!$this->mainChatMessageDiffResolved)
		{
			$this->mainChatMessageDiffResolved = true;
			$this->mainChatMessageDiff = $this->buildRecentPreviewRestPayload(
				$this->chat,
				$this->resolveRecentPreviewMessage($this->chat->getLastMessage()),
			);
		}

		return $this->mainChatMessageDiff;
	}

	// Memoized: the diff is identical for every recipient of this send.
	private function getHintCollabPreviewDiff(): ?array
	{
		if ($this->hintCollabPreviewDiffResolved)
		{
			return $this->hintCollabPreviewDiff;
		}

		$this->hintCollabPreviewDiffResolved = true;
		$this->hintCollabPreviewDiff = null;

		if (!Features::isCollabPreviewSourceEnabled())
		{
			return null;
		}

		$hint = $this->previewHint;
		$sourceMessage = $hint?->getSourceMessageHint();
		if ($hint === null || $sourceMessage === null)
		{
			return null;
		}

		$previewSource = $hint->getPreviewSource();

		// Reuse the collab chat when the source is its main chat; otherwise fetch the cached source chat instance.
		$sourceChat = ($previewSource->sourceChatId !== (int)$this->chat->getId())
			? Chat::getInstance($previewSource->sourceChatId)
			: $this->chat;

		$this->hintCollabPreviewDiff = $this->getCollabPreviewUserDiffParamsFromObjects(
			$this->chat,
			$previewSource,
			$sourceChat,
			$sourceMessage,
		);

		return $this->hintCollabPreviewDiff;
	}

	/**
	 * Batch-reads each recipient's stored preview-source pointer (authoritative: the triggering event
	 * already updated it) and caches it. A 0 pointer resolves to the collab main chat.
	 *
	 * @return array<int,PreviewSource> keyed by userId
	 */
	private function getCollabPreviewSources(): array
	{
		if ($this->collabPreviewSources !== null)
		{
			return $this->collabPreviewSources;
		}

		$this->collabPreviewSources = [];

		if (!Features::isCollabPreviewSourceEnabled())
		{
			return $this->collabPreviewSources;
		}

		$recipients = $this->getRecipients();
		if (empty($recipients))
		{
			return $this->collabPreviewSources;
		}

		$collabChatId = (int)$this->chat->getId();
		$mainLastMessageId = $this->chat->getLastMessage()?->getId() ?? 0;

		$rows = RecentTable::query()
			->setSelect(['USER_ID', 'PREVIEW_SOURCE_CID', 'PREVIEW_SOURCE_MID'])
			->where('ITEM_CID', $collabChatId)
			->whereIn('USER_ID', $recipients)
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$userId = (int)$row['USER_ID'];
			$sourceMessageId = (int)($row['PREVIEW_SOURCE_MID'] ?? 0);
			$sourceChatId = (int)($row['PREVIEW_SOURCE_CID'] ?? 0);

			$this->collabPreviewSources[$userId] = ($sourceMessageId > 0 && $sourceChatId > 0)
				? new PreviewSource(
					sourceChatId: $sourceChatId,
					messageId: $sourceMessageId,
					isMainChat: $sourceChatId === $collabChatId,
				)
				: PreviewSource::mainChat($collabChatId, $mainLastMessageId);
		}

		return $this->collabPreviewSources;
	}

	protected function getType(): EventType
	{
		return EventType::RecentUpdate;
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}
}
