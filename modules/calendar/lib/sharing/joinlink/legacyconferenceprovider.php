<?php

namespace Bitrix\Calendar\Sharing\JoinLink;

use Bitrix\Calendar\Sharing\Link\EventLink;
use Bitrix\Calendar\Sharing\SharingConference;
use Bitrix\Main\Error;

/**
 * Legacy join-link branch: delegates to the existing videoconference flow so
 * bookings with a filled CONFERENCE_ID and portals with the guest-chat gates
 * off keep their current behaviour.
 */
class LegacyConferenceProvider
{
	public function resolve(EventLink $eventLink): JoinLinkResult
	{
		try
		{
			$url = (new SharingConference($eventLink))->getConferenceLink();
		}
		catch (\Throwable)
		{
			$url = null;
		}

		if (empty($url))
		{
			return JoinLinkResult::failure(
				JoinLinkResult::BRANCH_LEGACY_CONFERENCE,
				[new Error('conference_link_unavailable')],
			);
		}

		return JoinLinkResult::success(JoinLinkResult::BRANCH_LEGACY_CONFERENCE, $url);
	}

	/**
	 * Resolves only the conference chat id. Uses getConferenceChatId() so the
	 * MANAGER_STARTED analytics attribution of the owner flow is preserved and
	 * no client link is generated.
	 */
	public function resolveChatId(EventLink $eventLink): JoinLinkResult
	{
		try
		{
			$chatId = (new SharingConference($eventLink))->getConferenceChatId();
		}
		catch (\Throwable)
		{
			$chatId = null;
		}

		if (empty($chatId))
		{
			return JoinLinkResult::failure(
				JoinLinkResult::BRANCH_LEGACY_CONFERENCE,
				[new Error('conference_chat_unavailable')],
			);
		}

		return JoinLinkResult::successChat(JoinLinkResult::BRANCH_LEGACY_CONFERENCE, $chatId);
	}
}
