<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service;

/**
 * Typed link "feed post <-> system message of the project (collab) chat".
 *
 * Internal module-boundary value object (socialnetwork owner -> im consumer):
 * fixes the actual contract of the IM_MESSAGE_ID + IM_CHAT_ID pair that was
 * previously returned as an untyped array ['imMessageId' => , 'imChatId' => ].
 *
 * Constructed only by the contract owner {@see LogImMessageLinkService} from a
 * link table row; there is no external input, so no validation is needed
 * (same shape as other internal value objects: {@see CollabRelationInfo}).
 */
final class LogImMessageLink
{
	public function __construct(
		public readonly int $imMessageId,
		public readonly int $imChatId,
	)
	{
	}
}
