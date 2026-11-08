<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\Config;

use Bitrix\Im\V2\Chat;

/**
 * Sections a recent row has to be removed from when the chat leaves the user's list - hidden by
 * hand, left or kicked. Membership is a property of the chat type, so subscription state is not
 * part of the answer: it is expressed by the presence of the recent row itself.
 */
final class HiddenSectionsResolver
{
	/**
	 * Open channels have their own tab with its own recent, populated from b_im_chat and not from
	 * b_im_recent. That showcase section therefore never belongs to a removal set.
	 *
	 * @see \Bitrix\Im\V2\Folder\System\AllOpenChannelFolder::RECENT_SECTION
	 */
	private const SHOWCASE_SECTION_NAME = 'openChannel';

	/**
	 * @return string[]
	 */
	public static function resolve(Chat $chat): array
	{
		// array_diff keeps the keys, and a gapped array would be serialized as an object, not a list.
		return array_values(array_diff($chat->getRecentSections(), [self::SHOWCASE_SECTION_NAME]));
	}
}
