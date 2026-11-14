<?php

namespace Bitrix\ImOpenLines\V2\Statistics\Dto;

use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\ImOpenLines\V2\Statistics\Dictionary;

/**
 * Read-only REST representation of a single session's batch metrics for
 * imopenlines.v2.Session.Stat.get.
 *
 * When the requested session does not exist or is not visible to the current user,
 * an instance is still built (via {@see self::empty()}) so the response always
 * contains one item per requested sessionId, with null metric fields.
 */
final class SessionStatItem implements RestConvertible
{
	private function __construct(
		private readonly int $sessionId,
		private readonly ?array $session,
		private readonly ?int $transfersCount,
		private readonly ?int $messagesOperatorCount,
		private readonly ?int $messagesClientCount,
	)
	{
	}

	public static function fromSession(
		int $sessionId,
		array $session,
		int $transfersCount,
		?int $messagesOperatorCount,
		?int $messagesClientCount
	): self
	{
		return new self($sessionId, $session, $transfersCount, $messagesOperatorCount, $messagesClientCount);
	}

	public static function empty(int $sessionId): self
	{
		return new self($sessionId, null, null, null, null);
	}

	public static function getRestEntityName(): string
	{
		return 'sessionStat';
	}

	public function toRestFormat(array $option = []): array
	{
		if ($this->session === null)
		{
			return [
				'sessionId' => $this->sessionId,
				'waitAnswer' => null,
				'waitClose' => null,
				'messagesCount' => null,
				'messagesOperatorCount' => null,
				'messagesClientCount' => null,
				'transfersCount' => null,
				'kpiFirstAnswer' => null,
				'vote' => null,
				'voteHead' => null,
			];
		}

		$session = $this->session;
		$voteHead = (int)($session['VOTE_HEAD'] ?? 0);

		$fields = [
			'sessionId' => $this->sessionId,
			'waitAnswer' => $this->nullableInt($session['TIME_FIRST_ANSWER'] ?? null),
			'waitClose' => $this->nullableInt($session['TIME_CLOSE'] ?? null),
			'messagesCount' => (int)($session['MESSAGE_COUNT'] ?? 0),
			'messagesOperatorCount' => $this->messagesOperatorCount,
			'messagesClientCount' => $this->messagesClientCount,
			'transfersCount' => $this->transfersCount,
			'kpiFirstAnswer' => $session['KPI_FIRST_ANSWER'] ?? null,
			'vote' => Dictionary::getVote((int)($session['VOTE'] ?? 0)),
			'voteHead' => $voteHead > 0 ? $voteHead : null,
		];

		return $fields;
	}

	private function nullableInt(mixed $value): ?int
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		$intValue = (int)$value;

		return $intValue > 0 ? $intValue : null;
	}
}
