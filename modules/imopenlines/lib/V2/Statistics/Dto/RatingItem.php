<?php

namespace Bitrix\ImOpenLines\V2\Statistics\Dto;

use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\ImOpenLines\V2\Statistics\Dictionary;
use Bitrix\Main\Type\DateTime;

/**
 * Read-only REST representation of a single voted session for
 * imopenlines.v2.Session.Rating.list.
 *
 * Built directly from a SessionTable row. `vote` is the client like/dislike enum
 * (Dictionary::getVote() over Session::VOTE); `voteHead` is a DIFFERENT, unrelated
 * 1..5 star supervisor rating (Session::VOTE_HEAD) and is exposed as a raw number,
 * not mapped through Dictionary::getVote() — see Dictionary's class doc. voteHead/
 * commentHead are masked to null by the caller via AccessGate::maskVoteHeadFieldsForConfig()
 * when the tariff and/or the caller's per-line vote-head permission does not allow
 * head rating.
 */
final class RatingItem implements RestConvertible
{
	public function __construct(
		private readonly array $session,
	)
	{
	}

	public static function getRestEntityName(): string
	{
		return 'rating';
	}

	public function toRestFormat(array $option = []): array
	{
		$session = $this->session;

		$voteHead = (int)($session['VOTE_HEAD'] ?? 0);

		return [
			'sessionId' => (int)$session['ID'],
			'configId' => (int)$session['CONFIG_ID'],
			'operatorId' => $this->nullableInt($session['OPERATOR_ID'] ?? null),
			'source' => (string)($session['SOURCE'] ?? ''),
			'vote' => Dictionary::getVote((int)($session['VOTE'] ?? 0)),
			'voteHead' => $voteHead > 0 ? $voteHead : null,
			'commentHead' => ($session['COMMENT_HEAD'] ?? '') !== '' ? (string)$session['COMMENT_HEAD'] : null,
			'dateVote' => $this->formatDate($session['DATE_CLOSE_VOTE'] ?? null),
			'dateSessionClose' => $this->formatDate($session['DATE_CLOSE'] ?? null),
		];
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

	private function formatDate(mixed $date): ?string
	{
		if ($date === null || $date === '')
		{
			return null;
		}

		if ($date instanceof DateTime)
		{
			return $date->format('c');
		}

		return (string)$date;
	}
}
