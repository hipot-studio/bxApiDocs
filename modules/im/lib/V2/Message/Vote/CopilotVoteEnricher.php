<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Vote;

/**
 * Injects the per-user `vote` state into an already-assembled `copilot.messages[]` REST block.
 *
 * `vote` is a PER-RECIPIENT personalization and is attached ONLY on synchronous per-user read
 * paths (message load/paging controller responses), where $userId is the authenticated request
 * user. It is deliberately kept OUT of the shared popup-DTO layer
 * (CopilotPopupItem / MessageCollection::getPopupData), because that layer is reused by broadcast
 * pull/push payloads (recent-preview pull, reply messageSend additionalEntities): there a single
 * base payload is built once (BaseEvent::getBasePullParams) and delivered to many recipients, so a
 * vote computed from the ambient context user would leak to everyone.
 */
class CopilotVoteEnricher
{
	/**
	 * Adds a `vote` map to every element of $rest['copilot']['messages'] on behalf of $userId.
	 * Value per message: {like:true} | {dislike:true} | {like:true,dislike:true} | null.
	 * No-op when there is no copilot block or the user is not resolved.
	 */
	public function enrich(array $rest, int $userId): array
	{
		if ($userId <= 0)
		{
			return $rest;
		}

		$messages = $rest['copilot']['messages'] ?? null;
		if (empty($messages) || !is_array($messages))
		{
			return $rest;
		}

		$messageIds = [];
		foreach ($messages as $message)
		{
			if (isset($message['id']))
			{
				$messageIds[] = (int)$message['id'];
			}
		}

		$votesMap = (new VoteService())->collectVotes($messageIds, $userId);

		foreach ($rest['copilot']['messages'] as &$message)
		{
			$voteMap = [];
			foreach ($votesMap[(int)($message['id'] ?? 0)] ?? [] as $vote)
			{
				$voteMap[$vote->value] = true;
			}

			$message['vote'] = $voteMap ?: null;
		}
		unset($message);

		return $rest;
	}
}
