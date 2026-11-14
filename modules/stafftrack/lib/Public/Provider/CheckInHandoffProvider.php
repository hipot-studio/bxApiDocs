<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\StaffTrack\Internal\Integration\Im\MessageService;
use Bitrix\StaffTrack\Internal\Service\CheckIn\HandoffIntentService;

/**
 * What the application is told when it starts by a check-in label: the chat the person picked on the
 * portal before the transition code was shown, or the bare expectation of one.
 *
 * The expectation is an answer of its own and not a consequence of the chat. The label handler serves
 * every way into the check-in, and only the server can tell "the person came from the paperclip, but the
 * code changed since" from "the person came from the More menu": the record of the intent is here.
 */
class CheckInHandoffProvider
{
	/**
	 * Hands the intent of the person over once. The name of the chat is resolved here because the
	 * application may know nothing about it, and a chat that stopped answering by the time the label
	 * arrived leaves the expectation without a chat rather than a chat without a name.
	 *
	 * Membership is asked again on the way out and not only when the intent was stored: between the
	 * shown code and the started application the person may have been removed from the chat (BRIEF,
	 * ERR-3), and the name of a chat one no longer belongs to must not travel back.
	 */
	public function consumeIntent(int $userId): array
	{
		$intent = $this->getIntentService()->consume($userId);
		if ($intent === null)
		{
			return ['chatExpected' => false];
		}

		$dialogId = $intent->getDeliverableDialogId();
		if ($dialogId === null || !$this->hasChatAccess($dialogId, $userId))
		{
			return ['chatExpected' => true];
		}

		$dialog = $this->resolveDialog($dialogId, $userId);
		if (empty($dialog['dialogId']))
		{
			return ['chatExpected' => true];
		}

		return [
			'chatExpected' => true,
			'dialogId' => $dialog['dialogId'],
			'dialogName' => $dialog['dialogName'],
			'source' => $intent->getDeliverableSource(),
		];
	}

	protected function getIntentService(): HandoffIntentService
	{
		return new HandoffIntentService();
	}

	protected function hasChatAccess(string $dialogId, int $userId): bool
	{
		return MessageService::hasDialogAccess($dialogId, $userId);
	}

	protected function resolveDialog(string $dialogId, int $userId): array
	{
		return MessageService::getDialogInfo($dialogId, $userId);
	}
}
