<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\User;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UserTable;

/**
 * Finds the active portal users an address belongs to without paying a scan of b_user on every letter.
 *
 * The comparison by value keeps the index on EMAIL and answers for every user whose address is stored
 * in the lower case, which on all but a few portals is every user there is. Only the addresses it
 * leaves unanswered reach the folded condition, and what that condition found is remembered per
 * address, so a screen of letters opened again asks nothing.
 *
 * Whether the portal keeps any such address at all is a question of its own, asked once and kept for an
 * hour ({@see MixedCaseAddressPresence}); a portal that keeps none never reaches the folded condition
 * on the reading of a photo.
 *
 * Remembered is the address - which users keep it in a case the comparison by value cannot match - and
 * never the photo. The row of the user is read anew on every call, so a photo changed a moment ago is
 * the one the letter shows.
 *
 * The two entries differ in what they are allowed to remember. {@see fetchActiveUsers()} serves the
 * reading of a photo and accepts an answer that is a few minutes old. {@see fetchCurrentActiveUsers()}
 * serves the writing of one, where the answer is the boundary of the feature itself - whether a shared
 * photo may be bound to the address at all - and a remembered "nobody keeps this address" would bind it
 * to an employee who has just appeared. It never reads the shared cache.
 */
final class UserAddressLookup
{
	private const CACHE_DIR = '/mail/sender_avatar_address_case';

	/**
	 * Matches the age {@see EmployeeAddressClassifier} accepts for the same question, and bounds the
	 * reading only: an employee whose address is kept in another case takes his own photo over the
	 * shared one within minutes of appearing on the portal.
	 */
	private const CACHE_TTL = 300;

	/** @var array<string, int[]> ids of the users keeping the address in another case, by address */
	private static array $answers = [];

	private \Closure $lookup;
	private MixedCaseAddressPresence $presence;

	public function __construct(?\Closure $lookup = null, ?MixedCaseAddressPresence $presence = null)
	{
		$this->lookup = $lookup ?? $this->lookupMixedCaseIds(...);
		$this->presence = $presence ?? new MixedCaseAddressPresence();
	}

	/**
	 * @param array<mixed> $emails
	 * @param string[] $select
	 * @return array<int, array<string, mixed>>
	 */
	public function fetchActiveUsers(array $emails, array $select): array
	{
		return $this->fetch($emails, $select, true);
	}

	/**
	 * Folds the case whatever the portal looks like, and takes no remembered answer of any kind. The
	 * caller that writes a photo pays this once per action of a person, and the answer is the boundary
	 * of the feature there.
	 *
	 * @param array<mixed> $emails
	 * @param string[] $select
	 * @return array<int, array<string, mixed>>
	 */
	public function fetchCurrentActiveUsers(array $emails, array $select): array
	{
		return $this->fetch($emails, $select, false);
	}

	/**
	 * @param array<mixed> $emails
	 * @param string[] $select
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch(array $emails, array $select, bool $mayRemember): array
	{
		$emails = UserAddressFilter::normalizeEmails($emails);
		if ($emails === [])
		{
			return [];
		}

		$rows = UserAddressFilter::applyExactEmails($this->query($select), $emails)->fetchAll();
		if (UserAddressFilter::isCaseFoldedByCollation())
		{
			return $rows;
		}

		return $this->byId($rows, $this->fetchMixedCase($emails, $select, $mayRemember));
	}

	/**
	 * The two conditions divide the users between themselves, so the same one comes back twice only
	 * when a remembered id belongs to a user who has since moved his address into the lower case.
	 *
	 * @param array<int, array<string, mixed>> ...$rowSets
	 * @return array<int, array<string, mixed>>
	 */
	private function byId(array ...$rowSets): array
	{
		$rows = [];
		foreach (array_merge(...$rowSets) as $row)
		{
			$rows[(int)$row['ID']] = $row;
		}

		return array_values($rows);
	}

	/**
	 * The users the comparison by value could not match keep their address in another case, so they are
	 * taken by id - by the primary key, and with the photo as it is right now.
	 *
	 * The address is asked of the row as well, because what was remembered is who held the address a
	 * few minutes ago. A user who has given it up since would otherwise be handed back for it and
	 * represent an address that is no longer his. The fold costs nothing here: the primary key has
	 * already picked the rows, and it is applied to those.
	 *
	 * On a portal that keeps no address in another case there is nobody here to find, and the reading
	 * leaves without asking anything at all - which is what takes the scan of b_user off that path. The
	 * writing does not take that shortcut: see {@see fetchCurrentActiveUsers()}.
	 *
	 * @param string[] $emails
	 * @param string[] $select
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchMixedCase(array $emails, array $select, bool $mayRemember): array
	{
		if ($mayRemember && !$this->presence->exists())
		{
			return [];
		}

		$ids = $mayRemember ? $this->rememberedIds($emails) : $this->ask($emails);
		if ($ids === [])
		{
			return [];
		}

		return UserAddressFilter::applyFoldedEmails($this->query($select)->whereIn('ID', $ids), $emails)
			->fetchAll()
		;
	}

	/**
	 * @param string[] $emails
	 * @return int[]
	 */
	private function rememberedIds(array $emails): array
	{
		$ids = [];
		$unknown = [];
		foreach ($emails as $email)
		{
			$remembered = self::$answers[$email] ?? $this->read($email);
			if ($remembered === null)
			{
				$unknown[] = $email;
				continue;
			}
			self::$answers[$email] = $remembered;
			$ids = array_merge($ids, $remembered);
		}

		return array_values(array_unique(array_merge($ids, $this->ask($unknown))));
	}

	/**
	 * @param string[] $emails
	 * @return int[]
	 */
	private function ask(array $emails): array
	{
		if ($emails === [])
		{
			return [];
		}

		$ids = [];
		foreach (($this->lookup)($emails) as $email => $found)
		{
			$found = array_values(array_unique(array_map('intval', $found)));
			$this->write((string)$email, $found);
			self::$answers[(string)$email] = $found;
			$ids = array_merge($ids, $found);
		}

		return array_values(array_unique($ids));
	}

	/**
	 * The engine folds the value and hands it back in the select, so the rows are keyed by its own fold
	 * and not by one repeated in PHP. A row already stored in the lower case is left out: it is what the
	 * comparison by value has matched, and remembering it would only make the caller ask for it twice.
	 *
	 * @param string[] $emails
	 * @return array<string, int[]>
	 */
	private function lookupMixedCaseIds(array $emails): array
	{
		$found = array_fill_keys($emails, []);
		$rows = UserAddressFilter::applyFoldedEmails($this->query(['ID', 'EMAIL']), $emails)
			->addSelect(UserAddressFilter::FOLDED_EMAIL_FIELD)
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$folded = (string)($row[UserAddressFilter::FOLDED_EMAIL_FIELD] ?? '');
			if ((string)$row['EMAIL'] === $folded || !isset($found[$folded]))
			{
				continue;
			}
			$found[$folded][] = (int)$row['ID'];
		}

		return $found;
	}

	/**
	 * @param string[] $select
	 */
	private function query(array $select): Query
	{
		return UserTable::query()
			->setSelect(array_values(array_unique(array_merge(['ID'], $select))))
			->where('ACTIVE', 'Y')
		;
	}

	/**
	 * @return int[]|null
	 */
	private function read(string $email): ?array
	{
		try
		{
			$cache = Cache::createInstance();
			if (!$cache->initCache(self::CACHE_TTL, self::cacheKey($email), self::cacheDir($email)))
			{
				return null;
			}
			$vars = $cache->getVars();
			$ids = is_array($vars) ? ($vars['ids'] ?? null) : null;

			return is_array($ids) ? array_map('intval', $ids) : null;
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * @param int[] $ids
	 */
	private function write(string $email, array $ids): void
	{
		try
		{
			$cache = Cache::createInstance();
			if ($cache->startDataCache(self::CACHE_TTL, self::cacheKey($email), self::cacheDir($email)))
			{
				$cache->endDataCache(['ids' => $ids]);
			}
		}
		catch (\Throwable)
		{
			// a cache that refuses to keep the answer must not take the answer away from the caller
		}
	}

	private static function cacheKey(string $email): string
	{
		return 'address-case-' . md5($email);
	}

	private static function cacheDir(string $email): string
	{
		return self::CACHE_DIR . '/' . substr(md5($email), 2, 2);
	}
}
