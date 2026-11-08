<?php

namespace Bitrix\Sign\Contract\Chat\Message;

use Bitrix\Sign\Contract\Chat\Message;

/**
 * Marks an invitation card whose participant state is tracked after sending. Such a card carries the
 * key of the member record it is addressed to, and the signing domain later finds it by that key to
 * repaint it when the participant is no longer expected to act.
 *
 * Implemented only by the invitation cards in scope. Result messages, B2B group invitations and the
 * employee-initiated scenario stay out: nothing looks them up.
 */
interface HasMemberKey extends Message
{
	/**
	 * Top-level message parameter carrying the key of the member record the card is addressed to.
	 * Top-level and scalar on purpose: only such a parameter reaches the indexed PARAM_VALUE column,
	 * and the lookup searches by it. Deliberately not registered in the chat type registry: an
	 * unregistered name is stored as a plain string parameter, which is all the lookup needs.
	 */
	public const MEMBER_KEY_PARAM = 'SIGN_MEMBER_ID';
}
