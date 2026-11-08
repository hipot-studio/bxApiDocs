<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Vote\Event;

use Bitrix\Im\V2\Common\Event\BaseEvent;
use Bitrix\Im\V2\Message\Vote\Vote;
use Bitrix\Main\EventResult;

class CollectVotesEvent extends BaseEvent
{
	public const TYPE = 'OnMessageVoteCollect';

	public function __construct(array $messageIds, int $userId)
	{
		parent::__construct(self::TYPE, ['messageIds' => $messageIds, 'userId' => $userId]);
	}

	/**
	 * Returns collected votes merged from all SUCCESS event results.
	 * Only messageIds that were requested are included; unknown ids from subscribers are discarded.
	 * @return array<int, Vote[]> [messageId => Vote[]]
	 */
	public function getCollectedVotes(): array
	{
		$requestedIds = array_map('intval', $this->getParameters()['messageIds'] ?? []);
		$requestedIdSet = array_flip($requestedIds);

		$collected = [];

		foreach ($this->getResults() as $result)
		{
			if ($result->getType() !== EventResult::SUCCESS)
			{
				continue;
			}

			$parameters = $result->getParameters();
			if (!is_array($parameters))
			{
				continue;
			}

			foreach ($parameters as $messageId => $values)
			{
				if (!is_array($values))
				{
					continue;
				}

				// Untrusted subscriber data: accept only int or pure-digit string keys
				// (avoid (int)'123abc' === 123 sneaking past the requested-id allowlist).
				if (
					!is_int($messageId)
					&& !(is_string($messageId) && preg_match('/^[0-9]+$/D', $messageId) === 1)
				)
				{
					continue;
				}

				$messageId = (int)$messageId;

				// Discard ids that were not in the original request
				if (!isset($requestedIdSet[$messageId]))
				{
					continue;
				}

				if (!isset($collected[$messageId]))
				{
					$collected[$messageId] = [];
				}

				foreach ($values as $value)
				{
					// Untrusted subscriber data: normalize type. A subscriber may legitimately
					// return Vote enum cases; any non-scalar (object/array) must be skipped, not
					// blindly cast to string — (string)$object would throw and break popup build.
					if ($value instanceof Vote)
					{
						$vote = $value;
					}
					elseif (is_string($value) || is_int($value))
					{
						$vote = Vote::tryFrom((string)$value);
					}
					else
					{
						continue;
					}

					if ($vote === null)
					{
						continue;
					}

					// Avoid duplicating the same enum case
					if (!in_array($vote, $collected[$messageId], true))
					{
						$collected[$messageId][] = $vote;
					}
				}
			}
		}

		// Return only non-empty entries
		return array_filter($collected, static fn(array $votes) => !empty($votes));
	}
}
