<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Internal\Digest\Reason\DigestReason;
use Bitrix\Main\Application;

/**
 * Remembers why a BI tool could not read the report for a recipient of a digest run.
 *
 * The AI service answers the run with no text and no reason when the agent gave up, so the step after
 * the agent reads the cause here: a report that did not answer in time is a temporary outcome of the
 * recipient and not a failure of the digest. The entry lives in the portal cache, because the tool is
 * called by the AI service in a request of its own; a lost entry reads as no entry, which reports the
 * failure to the recipient at once.
 *
 * An entry belongs to one attempt of the recipient and names the reads that failed in it: a tool together with
 * the arguments that decide its data, such as the charts it asked for. A call of an earlier attempt that fails
 * after the next one has started writes nothing, and a successful call of the same read takes it back off the
 * entry, so the entry speaks only of failures the current attempt has not got past. A tool that answered for
 * other charts says nothing of the ones it failed to read.
 */
final class DigestToolFailureJournal
{
	private const TTL = 86400;
	private const CACHE_DIR = '/biconnector/digest_tool_failure/';
	private const KEY_PREFIX = 'digest_tool_failure_';
	private const ATTEMPT_KEY_PREFIX = 'digest_tool_attempt_';

	private const ENTRY_ATTEMPT = 'attempt';
	private const ENTRY_REASON = 'reason';
	private const ENTRY_TOOLS = 'tools';

	/**
	 * @param string $attempt The attempt the failed call belongs to, as getAttempt() gave it when the call started.
	 * @param string $call The read that failed: the tool and the arguments that decide its data.
	 */
	public function record(
		string $runId,
		int $userId,
		DigestReason $reason,
		string $attempt = '',
		string $call = '',
	): void
	{
		if ($attempt !== $this->getAttempt($runId, $userId))
		{
			return;
		}

		$entry = $this->readEntry($runId, $userId);
		$tools = $entry === null ? [] : $entry[self::ENTRY_TOOLS];
		$tools[$call] = true;

		$this->write(self::getKey($runId, $userId), [
			self::ENTRY_ATTEMPT => $attempt,
			self::ENTRY_REASON => $reason->code(),
			self::ENTRY_TOOLS => $tools,
		]);
	}

	public function find(string $runId, int $userId): ?DigestReason
	{
		$entry = $this->readEntry($runId, $userId);
		if ($entry === null || $entry[self::ENTRY_TOOLS] === [])
		{
			return null;
		}

		return DigestReason::tryFrom($entry[self::ENTRY_REASON]);
	}

	/**
	 * The report answered the same read this time: its earlier failure in the same attempt no longer stands.
	 */
	public function clear(string $runId, int $userId, string $attempt = '', string $call = ''): void
	{
		$entry = $this->readEntry($runId, $userId);
		if ($entry === null || $entry[self::ENTRY_ATTEMPT] !== $attempt || !isset($entry[self::ENTRY_TOOLS][$call]))
		{
			return;
		}

		unset($entry[self::ENTRY_TOOLS][$call]);
		if ($entry[self::ENTRY_TOOLS] === [])
		{
			Application::getInstance()->getCache()->clean(self::getKey($runId, $userId), self::CACHE_DIR);

			return;
		}

		$this->write(self::getKey($runId, $userId), $entry);
	}

	/**
	 * Starts the next attempt of the recipient. An entry speaks of one attempt: left in place, it would turn
	 * a refusal of the model on the next attempt into one more report that did not answer in time.
	 */
	public function forget(string $runId, int $userId): void
	{
		Application::getInstance()->getCache()->clean(self::getKey($runId, $userId), self::CACHE_DIR);
		$this->write(self::getAttemptKey($runId, $userId), bin2hex(random_bytes(8)));
	}

	/**
	 * @return string The current attempt of the recipient; empty before the first one or when the cache lost it.
	 */
	public function getAttempt(string $runId, int $userId): string
	{
		$attempt = $this->read(self::getAttemptKey($runId, $userId));

		return is_string($attempt) ? $attempt : '';
	}

	/**
	 * @return array{attempt: string, reason: string, tools: array<string, true>}|null Null when there is no entry
	 *     of the current attempt.
	 */
	private function readEntry(string $runId, int $userId): ?array
	{
		$entry = $this->read(self::getKey($runId, $userId));
		if (
			!is_array($entry)
			|| !is_string($entry[self::ENTRY_ATTEMPT] ?? null)
			|| !is_string($entry[self::ENTRY_REASON] ?? null)
			|| !is_array($entry[self::ENTRY_TOOLS] ?? null)
			|| $entry[self::ENTRY_ATTEMPT] !== $this->getAttempt($runId, $userId)
		)
		{
			return null;
		}

		return $entry;
	}

	private function read(string $key): mixed
	{
		$cache = Application::getInstance()->getCache();
		if (!$cache->initCache(self::TTL, $key, self::CACHE_DIR))
		{
			return null;
		}

		return $cache->getVars();
	}

	private function write(string $key, mixed $value): void
	{
		$cache = Application::getInstance()->getCache();
		$cache->forceRewriting(true);
		if ($cache->startDataCache(self::TTL, $key, self::CACHE_DIR))
		{
			$cache->endDataCache($value);
		}
	}

	private static function getKey(string $runId, int $userId): string
	{
		return self::KEY_PREFIX . $userId . '_' . $runId;
	}

	private static function getAttemptKey(string $runId, int $userId): string
	{
		return self::ATTEMPT_KEY_PREFIX . $userId . '_' . $runId;
	}
}
