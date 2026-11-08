<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\User;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\UserTable;

/**
 * Answers one question about the portal as a whole: does any active user keep his address in a case the
 * comparison by value cannot match?
 *
 * The folded condition exists for such users alone. A portal that has none of them - and that is the
 * usual portal - skips the folded condition on the reading of a photo entirely, and the scan of b_user
 * leaves the reading path with it. The addresses of external senders are what made the arrangement
 * expensive without this question: an external sender is no portal user at all, so the comparison by
 * value never matches him, and the folded condition was spent on every address the reading had not seen
 * before. The feature lives on exactly those addresses.
 *
 * The question itself is a scan when the answer is "no", because there is nothing to stop at. That is
 * why it is asked of the portal once and kept, instead of being asked of every address.
 *
 * Saving a user drops the kept answer as soon as the address he was saved with differs from its own
 * fold, so the very first address of that kind on a portal is seen at once rather than after the answer
 * runs out ({@see \Bitrix\Mail\Integration\Main\User\AddressCaseHandler}). The lifetime stays on as
 * the safety net for the ways an address reaches the table without that event.
 */
final class MixedCaseAddressPresence
{
	private const CACHE_DIR = '/mail/sender_avatar_address_case/portal';
	private const CACHE_KEY = 'mixed-case-addresses';

	/**
	 * An hour, against the five minutes an answer about a single address keeps. Three things set it.
	 * The answer is about the portal and not about a person, and it turns over only when the very first
	 * address in another case appears there - a one-time event, not a daily one. Being wrong costs a
	 * photo and nothing else: for the length of the window a brand new employee of that kind loses the
	 * priority of his own photo, while the boundary of the feature holds, because the writing of a photo
	 * folds the case whatever this answer is. And the question is a scan, so a short window would bring
	 * back the very cost it removes: at five minutes the portal would pay twelve scans an hour, which is
	 * about what the reading path spends without this question at all. Saving a user drops the answer on
	 * the spot, so this lifetime only bounds the ways that reach the table without an event of the
	 * kernel - an import, a migration, a hand at the database.
	 */
	private const CACHE_TTL = 3600;

	private static ?bool $answer = null;

	private \Closure $probe;

	public function __construct(?\Closure $probe = null)
	{
		$this->probe = $probe ?? $this->probePortal(...);
	}

	/**
	 * Drops the kept answer, so the next question is asked of the portal as it is now. Costs nothing
	 * itself, which is what lets the caller sit on an event of the kernel.
	 */
	public static function forget(): void
	{
		self::$answer = null;
		try
		{
			Cache::createInstance()->clean(self::CACHE_KEY, self::CACHE_DIR);
		}
		catch (\Throwable)
		{
			// the answer outlives its drop for at most its own lifetime, and that is the safety net anyway
		}
	}

	public function exists(): bool
	{
		if (self::$answer !== null)
		{
			return self::$answer;
		}

		return self::$answer = $this->read() ?? $this->ask();
	}

	private function ask(): bool
	{
		try
		{
			$exists = (bool)($this->probe)();
		}
		catch (\Throwable)
		{
			// a portal whose answer cannot be established keeps the folded condition: slower, never wrong
			return true;
		}
		$this->write($exists);

		return $exists;
	}

	/**
	 * Stops at the first user found, and reads the table through when there is none. `LOWER` here is the
	 * same fold the condition of the lookup applies, so the two agree on what the comparison by value
	 * can and cannot match - including on an engine whose collation folds the case by itself, where the
	 * answer is "no" because there the comparison by value matches every case already.
	 */
	private function probePortal(): bool
	{
		return (bool)UserTable::query()
			->setSelect(['ID'])
			->where('ACTIVE', 'Y')
			->whereExpr('%s <> LOWER(%s)', ['EMAIL', 'EMAIL'])
			->setLimit(1)
			->fetch()
		;
	}

	private function read(): ?bool
	{
		try
		{
			$cache = Cache::createInstance();
			if (!$cache->initCache(self::CACHE_TTL, self::CACHE_KEY, self::CACHE_DIR))
			{
				return null;
			}
			$vars = $cache->getVars();

			return is_array($vars) && is_bool($vars['exists'] ?? null) ? $vars['exists'] : null;
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function write(bool $exists): void
	{
		try
		{
			$cache = Cache::createInstance();
			if ($cache->startDataCache(self::CACHE_TTL, self::CACHE_KEY, self::CACHE_DIR))
			{
				$cache->endDataCache(['exists' => $exists]);
			}
		}
		catch (\Throwable)
		{
			// a cache that refuses to keep the answer must not take the answer away from the caller
		}
	}
}
