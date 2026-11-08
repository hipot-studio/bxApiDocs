<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\User;

use Bitrix\Mail\Integration\HumanResources\EmployeeIdChecker;
use Bitrix\Main\Data\Cache;

/**
 * Answers whether an address belongs to an active employee of the portal. The question is asked again
 * on every request of a protected sender image, which is why the answer is remembered - in the memory
 * of the request and, for a short while, in the shared cache. The lookup itself goes through
 * {@see UserAddressLookup}, which keeps the index on b_user.EMAIL wherever the stored case allows it.
 *
 * The two entries differ in what they are allowed to remember, and the difference runs all the way down
 * into the lookup. {@see classify()} serves the reading of a photo and accepts an answer that is a few
 * minutes old, so the lookup may answer it from what it remembers as well. {@see classifyCurrent()}
 * serves the writing of one, where the answer is the boundary of the feature itself - whether a shared
 * photo may be bound to the address at all - and an answer that has outlived its time would bind it to
 * an address that has just become an employee of the portal. It reads neither the shared cache nor any
 * remembered answer of the lookup.
 *
 * Only a definite answer is remembered. An address whose status could not be established stays unknown
 * on every call, so the caller keeps refusing on it.
 */
final class EmployeeAddressClassifier
{
	private const CACHE_DIR = '/mail/sender_avatar_employee';

	/**
	 * Short enough for a new employee to take his own photo over the shared one within minutes, long
	 * enough for a chain of letters to ask about one address once. It bounds the reading only. A stale
	 * "not an employee" changes nothing the reader sees: the priority of the photo is built without this
	 * cache and already prefers the employee. A stale "an employee" only refuses, which is the safe
	 * side. The writing of a photo does not take an answer of this age at all, see {@see classifyCurrent()}.
	 */
	private const CACHE_TTL = 300;

	/** @var array<string, bool> */
	private array $answers = [];

	/** @var array<string, bool> answers established from the portal right now, never remembered ones */
	private array $currentAnswers = [];

	private \Closure $lookup;

	public function __construct(?\Closure $lookup = null)
	{
		$this->lookup = $lookup ?? $this->lookupEmployee(...);
	}

	public function classify(string $email): ?bool
	{
		if ($email === '')
		{
			return null;
		}
		if (isset($this->answers[$email]))
		{
			return $this->answers[$email];
		}

		$cached = $this->read($email);
		if ($cached === null)
		{
			return $this->ask($email, true);
		}

		return $this->answers[$email] = $cached;
	}

	public function classifyCurrent(string $email): ?bool
	{
		if ($email === '')
		{
			return null;
		}

		return $this->currentAnswers[$email] ?? $this->ask($email, false);
	}

	/**
	 * Only an answer the lookup established from the portal right now counts as current. One it was
	 * allowed to take from what it remembers may be minutes old, and letting it settle here would hand
	 * that age on to the writing of a photo through the back door.
	 */
	private function ask(string $email, bool $mayRemember): ?bool
	{
		$isEmployee = ($this->lookup)($email, $mayRemember);
		if (!is_bool($isEmployee))
		{
			return null;
		}
		$this->write($email, $isEmployee);
		if (!$mayRemember)
		{
			$this->currentAnswers[$email] = $isEmployee;
		}

		return $this->answers[$email] = $isEmployee;
	}

	private function read(string $email): ?bool
	{
		try
		{
			$cache = Cache::createInstance();
			if (!$cache->initCache(self::CACHE_TTL, self::cacheKey($email), self::CACHE_DIR))
			{
				return null;
			}
			$vars = $cache->getVars();

			return is_array($vars) && is_bool($vars['isEmployee'] ?? null) ? $vars['isEmployee'] : null;
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function write(string $email, bool $isEmployee): void
	{
		try
		{
			$cache = Cache::createInstance();
			if ($cache->startDataCache(self::CACHE_TTL, self::cacheKey($email), self::CACHE_DIR))
			{
				$cache->endDataCache(['isEmployee' => $isEmployee]);
			}
		}
		catch (\Throwable)
		{
			// a cache that refuses to keep the answer must not take the answer away from the caller
		}
	}

	private static function cacheKey(string $email): string
	{
		return 'is-employee-' . md5($email);
	}

	private function lookupEmployee(string $email, bool $mayRemember): ?bool
	{
		return (new EmployeeIdChecker())->containsEmployee($this->activeUserIdsByEmail($email, $mayRemember));
	}

	/**
	 * Hands the same reading and writing split on to the lookup. The reading of a photo lets it answer
	 * from what it remembers, which on the usual portal costs no scan of the users at all; the writing
	 * of one demands the portal as it is now.
	 *
	 * @return int[]
	 */
	private function activeUserIdsByEmail(string $email, bool $mayRemember): array
	{
		$lookup = new UserAddressLookup();
		$users = $mayRemember
			? $lookup->fetchActiveUsers([$email], ['ID'])
			: $lookup->fetchCurrentActiveUsers([$email], ['ID'])
		;

		return array_map(static fn(array $user): int => (int)$user['ID'], $users);
	}
}
