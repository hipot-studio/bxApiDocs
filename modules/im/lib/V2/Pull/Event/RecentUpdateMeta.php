<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Chat;

/**
 * Info-only variant of {@see RecentUpdate}: signals the client to refresh chat metadata
 * (preview, recentConfig, parentChatId, ...) without moving the recent item.
 *
 * The event always carries `lastActivityDate = null`; the frontend treats null as
 * "no change to this field" and keeps the existing recent item position.
 *
 * Use when a user must learn about a chat in their recent list but the originating activity
 * did not actually bump the user's recent — e.g. a sibling chat received a message but the
 * counter for this user wasn't incremented or the source child chat was muted.
 */
final class RecentUpdateMeta extends RecentUpdate
{
	/**
	 * @param int[] $recipients
	 */
	public function __construct(Chat $chat, array $recipients)
	{
		parent::__construct($chat, $recipients);
	}

	protected function getBasePullParamsInternal(): array
	{
		// Reuse the parent builder so the collab base drops `message` too (avoids cross-chat params leak).
		return $this->buildBasePreviewParams(null);
	}
}
