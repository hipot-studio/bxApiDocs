<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\File\Upload;

/**
 * Removes the upload sessions the caller has abandoned together with the bytes of the ui circuit
 * behind them. Registered in install/migrations/agents.php with an interval of 3600 seconds.
 *
 * 🔴 AN AGENT RUNS INSIDE THE HIT OF WHOEVER HAPPENED TO ARRIVE when cron agents are off, so a run is
 * bounded by time and not only by a count: a pack of sessions costs a batch of the ui circuit, and on a
 * cloud portal that batch means network deletes. The shape is the one ui already uses for the same work -
 * {@see \Bitrix\UI\FileUploader\TempFileAgent} - including the short interval while a tail is left: a
 * fixed hour with a fixed portion means a caller who opens sessions faster than that outruns the cleanup
 * forever.
 *
 * A session somebody is writing is never touched and never waited for: the removal asks for the named lock
 * of the session, steps over the ones it does not get and goes on to the queue behind them
 * ({@see UploadSessionService::dropPack()}).
 */
final class UploadSessionCleanupAgent
{
	private const DEFAULT_SETTINGS = [
		'portion' => 500,
		'packSize' => 50,
		'budgetSeconds' => 20,
		'busyInterval' => 120,
		// The interval agents.php registers: the agent returns to it as soon as nothing is left behind.
		'idleInterval' => 3600,
	];

	private UploadSessionService $sessions;
	/** @var \Closure(): int */
	private \Closure $clock;
	/** @var array<string, int> */
	private array $settings;

	/**
	 * @param callable(): int|null $clock Monotonic time in nanoseconds.
	 * @param array<string, int>|null $settings Overrides of DEFAULT_SETTINGS; a seam for the tests.
	 */
	public function __construct(
		?UploadSessionService $sessions = null,
		?callable $clock = null,
		?array $settings = null,
	)
	{
		$this->sessions = $sessions ?? new UploadSessionService();
		$this->clock = $clock === null ? static fn (): int => hrtime(true) : \Closure::fromCallable($clock);
		$this->settings = self::normalizeSettings($settings ?? []);
	}

	public static function cleanAgent(): string
	{
		(new self())->run();

		return self::getAgentName();
	}

	/**
	 * Removes expired sessions in packs until the portion or the time budget is used up, then asks for a
	 * short interval while a tail may still be there.
	 *
	 * @return int Number of sessions removed.
	 */
	public function run(): int
	{
		$portion = $this->settings['portion'];
		$budget = $this->settings['budgetSeconds'] * 1_000_000_000;

		$startedAt = ($this->clock)();
		$removed = 0;
		$tailLeft = false;
		$failedIds = [];

		while ($removed < $portion)
		{
			$size = min($this->settings['packSize'], $portion - $removed);
			$expired = $this->sessions->findExpired($size, $failedIds);
			if ($expired->count() === 0)
			{
				break;
			}

			$packFailedIds = $this->sessions->dropPack($expired);
			// A session that could not be removed - one somebody is writing right now among them
			// ({@see UploadSessionService::dropPack()}) - stays in the table and is left out of the next
			// packs, so it uses up neither the portion nor every run that follows.
			$failedIds = array_merge($failedIds, $packFailedIds);
			$removed += $expired->count() - count($packFailedIds);

			$tailLeft = $expired->count() === $size;
			if (!$tailLeft || ($this->clock)() - $startedAt >= $budget)
			{
				break;
			}
		}

		$this->updateInterval($tailLeft ? $this->settings['busyInterval'] : $this->settings['idleInterval']);

		return $removed;
	}

	private function updateInterval(int $interval): void
	{
		$agent = \CAgent::getList(['ID' => 'DESC'], [
			'MODULE_ID' => 'im',
			'=NAME' => self::getAgentName(),
		])->fetch();

		if ($agent && (int)$agent['AGENT_INTERVAL'] !== $interval)
		{
			\CAgent::update($agent['ID'], ['AGENT_INTERVAL' => $interval]);
		}
	}

	private static function getAgentName(): string
	{
		// Must match the registered agent NAME with no leading backslash: the core rewrites
		// b_agent.NAME with this return value on every run.
		return 'Bitrix\\Im\\V2\\Entity\\File\\Upload\\UploadSessionCleanupAgent::cleanAgent();';
	}

	/**
	 * @param array<string, int> $settings
	 *
	 * @return array<string, int> A missing or non-positive value falls back to its default.
	 */
	private static function normalizeSettings(array $settings): array
	{
		$result = [];
		foreach (self::DEFAULT_SETTINGS as $key => $default)
		{
			$value = (int)($settings[$key] ?? 0);
			$result[$key] = $value > 0 ? $value : $default;
		}

		return $result;
	}
}
