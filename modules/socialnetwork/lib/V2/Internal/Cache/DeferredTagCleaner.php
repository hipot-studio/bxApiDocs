<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Cache;

use Bitrix\Main\Application;
use Throwable;

/**
 * Deferred invalidation of global permission cache tags: it collects the claimed tags and clears
 * them once, after the response. The caller decides whether a tag goes here or is cleared at once.
 */
final class DeferredTagCleaner
{
	private static ?self $instance = null;

	/** @var array<string, true> claimed tags, a set: the same tag never clears twice */
	private array $tags = [];

	private bool $jobRegistered = false;

	private function __construct()
	{
	}

	public static function getInstance(): self
	{
		if (self::$instance === null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function clearByTag(string $tag): void
	{
		$this->tags[$tag] = true;

		if (!$this->jobRegistered)
		{
			$this->jobRegistered = true;

			// Released inside the job, before the clearing: in the public flush() it would regrow the kernel job
			// queue in a process that never terminates; after the clearing it would strand a claimed tag
			Application::getInstance()->addBackgroundJob(
				function (): void {
					$this->jobRegistered = false;
					$this->flush();
				}
			);
		}
	}

	/**
	 * Clears the claimed tags. Long-running processes call it after their own commit; in a web hit
	 * the background job of the kernel does.
	 */
	public function flush(): void
	{
		$pending = $this->tags;

		// Drained before clearing: a tag claimed while clearing belongs to the next set, not to this one
		$this->tags = [];

		$failure = null;

		foreach (array_keys($pending) as $tag)
		{
			try
			{
				$this->clearTag($tag);
			}
			catch (Throwable $e)
			{
				// A tag that fails to clear must not strand the rest of the set, and it is not claimed
				// again: a re-claim would register another background job, and the kernel job loop takes
				// jobs from jobs, so a standing DB failure would spin. The failure is not swallowed
				// either: it leaves flush() once the set is done, as it does without a set at all
				$failure ??= $e;
			}
		}

		if ($failure !== null)
		{
			throw $failure;
		}
	}

	private function clearTag(string $tag): void
	{
		if (!defined('BX_COMP_MANAGED_CACHE'))
		{
			return;
		}

		Application::getInstance()->getTaggedCache()->clearByTag($tag);
	}
}
