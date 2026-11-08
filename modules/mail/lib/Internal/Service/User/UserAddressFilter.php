<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\User;

use Bitrix\Main\Application;
use Bitrix\Main\DB\MysqlCommonConnection;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;

/**
 * Builds the two conditions that look a portal user up by address, and tells which of them the engine
 * makes enough. The address of a letter is folded to the lower case before the lookup, while
 * b_user.EMAIL keeps the case it was typed in, so on an engine that compares case-sensitively the two
 * sides meet only when the case is folded in the query as well. An employee the lookup misses passes
 * for an external sender: a shared photo gets assigned to him, and his own photo stops winning the
 * chain of the letter.
 *
 * Which condition to spend on which address is decided by {@see UserAddressLookup}: the folded one
 * costs a scan of b_user and must not be paid on every letter.
 */
final class UserAddressFilter
{
	public const FOLDED_EMAIL_FIELD = 'EMAIL_LOWER';

	/**
	 * Where the collation folds the case, the comparison by value already matches every variant of the
	 * address and the folded condition has nothing left to find.
	 */
	public static function isCaseFoldedByCollation(): bool
	{
		return Application::getConnection() instanceof MysqlCommonConnection;
	}

	/**
	 * Compares the address with the value as it is stored, which is what the index on EMAIL is built
	 * for. On a case-sensitive engine it matches an employee whose address is stored in the lower case -
	 * that is every employee of all but a few portals.
	 *
	 * @param array<mixed> $emails
	 */
	public static function applyExactEmails(Query $query, array $emails): Query
	{
		$emails = self::normalizeEmails($emails);

		return $emails === [] ? self::matchNobody($query) : $query->whereIn('EMAIL', $emails);
	}

	/**
	 * Folds the case in the query itself. On its own the folded column no longer matches the index on
	 * EMAIL and the condition costs a scan of the users, so it is spent only on the addresses the
	 * comparison by value leaves unanswered. Added on top of a condition that already picks the rows -
	 * by primary key, say - it costs nothing: the fold is applied to what is picked.
	 *
	 * Registers {@see FOLDED_EMAIL_FIELD}, so a caller that has to key the rows by address takes the
	 * value the engine folded - `addSelect(UserAddressFilter::FOLDED_EMAIL_FIELD)` - instead of folding
	 * the stored one again in PHP, where an address the two fold differently would lose its row.
	 *
	 * @param array<mixed> $emails
	 */
	public static function applyFoldedEmails(Query $query, array $emails): Query
	{
		$emails = self::normalizeEmails($emails);
		if ($emails === [])
		{
			return self::matchNobody($query);
		}

		return $query
			->registerRuntimeField(new ExpressionField(self::FOLDED_EMAIL_FIELD, 'LOWER(%s)', 'EMAIL'))
			->whereIn(self::FOLDED_EMAIL_FIELD, $emails)
		;
	}

	/**
	 * Addresses may come in any case; anything in the list that is not an address is dropped rather than
	 * raised on, because the list is assembled by the caller and this method promises no exceptions.
	 *
	 * @param array<mixed> $emails
	 * @return string[]
	 */
	public static function normalizeEmails(array $emails): array
	{
		$normalized = [];
		foreach ($emails as $email)
		{
			$email = is_scalar($email) ? mb_strtolower(trim((string)$email)) : '';
			if ($email !== '')
			{
				$normalized[] = $email;
			}
		}

		return array_values(array_unique($normalized));
	}

	private static function matchNobody(Query $query): Query
	{
		// a query left without a condition would hand out every user of the portal
		return $query->whereExpr('1 = 0', []);
	}
}
