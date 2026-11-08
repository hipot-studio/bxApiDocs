<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Internal\Digest\Reason\DigestReason;
use Bitrix\BIConnector\Internal\Digest\Reason\DigestReasonClass;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserTable;

/**
 * Turns the problems of a digest run into the lines of the notice for the person who set it up.
 *
 * A problem is stored as one value "<reason code>:user_<id>", so a person never drifts away from the
 * reason: lists of names and of reasons kept side by side used to pair people with reasons of others.
 * Nothing here rests on the order of the values either: lines follow the order of the reasons, names
 * are sorted, and the fingerprint is the sorted list of pairs, so the same people in another order are
 * no new notice.
 */
final class ProblemNoticeComposer
{
	private const NAMED_LIMIT = 3;
	private const PROBLEM_PATTERN = '/^([a-z_]+):user_(\d+)$/';

	/** @var \Closure(list<int>): array<int, string> */
	private readonly \Closure $loadNames;

	/**
	 * @param (\Closure(list<int>): array<int, string>)|null $loadNames User id => name to show; a user
	 *     it leaves out is counted but not named.
	 */
	public function __construct(?\Closure $loadNames = null)
	{
		$this->loadNames = $loadNames ?? self::loadUserNames(...);
	}

	/**
	 * @param list<mixed> $problems Values of the problem field as the storage returned them.
	 *
	 * @return array{lines: list<string>, fingerprint: string, count: int}
	 */
	public function compose(array $problems): array
	{
		$userIdsByReason = $this->parse($problems);

		$pairs = [];
		foreach ($userIdsByReason as $code => $userIds)
		{
			foreach ($userIds as $userId)
			{
				$pairs[] = $code . ':user_' . $userId;
			}
		}
		sort($pairs, SORT_STRING);

		return [
			'lines' => $this->buildLines($userIdsByReason),
			'fingerprint' => implode(',', $pairs),
			'count' => count($pairs),
		];
	}

	/**
	 * Values of an unknown shape and codes of no reason are dropped; a pair met twice counts once.
	 *
	 * @return array<string, list<int>> Reason code => user ids.
	 */
	private function parse(array $problems): array
	{
		$userIdsByReason = [];
		foreach (self::flatten($problems) as $problem)
		{
			if (!is_string($problem) || !preg_match(self::PROBLEM_PATTERN, trim($problem), $matches))
			{
				continue;
			}

			$reason = DigestReason::tryFrom($matches[1]);
			$userId = (int)$matches[2];
			if ($reason === null || $userId <= 0)
			{
				continue;
			}

			$userIdsByReason[$reason->code()][$userId] = $userId;
		}

		return array_map(array_values(...), $userIdsByReason);
	}

	/**
	 * @param array<string, list<int>> $userIdsByReason
	 *
	 * @return list<string>
	 */
	private function buildLines(array $userIdsByReason): array
	{
		if ($userIdsByReason === [])
		{
			return [];
		}

		$names = ($this->loadNames)(array_values(array_unique(array_merge(...array_values($userIdsByReason)))));

		$lines = [];
		foreach (DigestReason::cases() as $reason)
		{
			$userIds = $userIdsByReason[$reason->code()] ?? [];
			if ($userIds === [])
			{
				continue;
			}

			$lines[] = $reason->isAboutSetupAsWhole()
				? $reason->setupText()
				: $this->buildRecipientsLine($reason, $userIds, $names)
			;
		}

		return $lines;
	}

	/**
	 * @param list<int> $userIds
	 * @param array<int, string> $names
	 */
	private function buildRecipientsLine(DigestReason $reason, array $userIds, array $names): string
	{
		$named = [];
		foreach ($userIds as $userId)
		{
			$name = self::neutralizeBbCode($names[$userId] ?? '');
			if ($name !== '')
			{
				$named[$userId] = $name;
			}
		}

		uksort(
			$named,
			static fn(int $left, int $right): int =>
				[mb_strtolower($named[$left]), $left] <=> [mb_strtolower($named[$right]), $right],
		);

		$count = count($userIds);
		$shown = array_slice(array_values($named), 0, self::NAMED_LIMIT);
		$rest = $count - count($shown);
		$replace = [
			'#COUNT#' => $count,
			'#REASON#' => $this->reasonText($reason),
			'#NAMES#' => implode(', ', $shown),
			'#REST#' => $rest,
		];

		if ($shown === [])
		{
			return (string)Loc::getMessagePlural('BI_DIGEST_PROBLEM_NOTICE_LINE_UNNAMED', $count, $replace);
		}

		return (string)Loc::getMessagePlural(
			$rest > 0 ? 'BI_DIGEST_PROBLEM_NOTICE_LINE_MORE' : 'BI_DIGEST_PROBLEM_NOTICE_LINE',
			$count,
			$replace,
		);
	}

	/**
	 * The reason stands after "N recipients have ...", so it takes the wording of the setup form, which is
	 * written for a place beside the people it is about. Temporary reasons never reach that form and have
	 * a wording of their own here.
	 */
	private function reasonText(DigestReason $reason): string
	{
		$key = match ($reason)
		{
			DigestReason::BiPreparing => 'BI_DIGEST_PROBLEM_NOTICE_REASON_BI_PREPARING',
			DigestReason::DashboardPreparing => 'BI_DIGEST_PROBLEM_NOTICE_REASON_DASHBOARD_PREPARING',
			DigestReason::DashboardNotSynced => 'BI_DIGEST_PROBLEM_NOTICE_REASON_DASHBOARD_NOT_SYNCED',
			DigestReason::WarmupFailed => 'BI_DIGEST_PROBLEM_NOTICE_REASON_WARMUP_FAILED',
			DigestReason::DashboardTimeout => 'BI_DIGEST_PROBLEM_NOTICE_REASON_DASHBOARD_TIMEOUT',
			default => null,
		};

		if ($key !== null)
		{
			return Loc::getMessage($key) ?? $reason->text();
		}

		return $reason->reasonClass() === DigestReasonClass::Settings ? $reason->setupText() : $reason->text();
	}

	/**
	 * A name is chosen by its owner, and the notice is read as BB code by the chat. Brackets are replaced
	 * instead of entity-encoded, the way the chat neutralizes file names: the chat decodes &#91; back to '['
	 * before the text reaches clients. For the same reason the name is decoded first, until nothing is left
	 * to decode, so a bracket written as an entity in any form, encoded once or many times, is replaced
	 * as well. Line breaks would let a name start a line of its own, which the chat reads as markup too.
	 */
	private static function neutralizeBbCode(string $name): string
	{
		do
		{
			$encoded = $name;
			$name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
		}
		while ($name !== $encoded);

		$name = preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $name) ?? '';

		return trim(str_replace(['[', ']'], ['(', ')'], $name));
	}

	private static function flatten(array $values): array
	{
		$flat = [];
		array_walk_recursive($values, static function (mixed $value) use (&$flat): void {
			$flat[] = $value;
		});

		return $flat;
	}

	/**
	 * @param list<int> $userIds
	 *
	 * @return array<int, string>
	 */
	private static function loadUserNames(array $userIds): array
	{
		if ($userIds === [])
		{
			return [];
		}

		$rows = UserTable::getList([
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'EMAIL'],
			'filter' => ['@ID' => $userIds],
		]);

		$names = [];
		$format = \CSite::GetNameFormat(false);
		while ($row = $rows->fetch())
		{
			$names[(int)$row['ID']] = (string)\CUser::FormatName($format, $row, true, false);
		}

		return $names;
	}
}
