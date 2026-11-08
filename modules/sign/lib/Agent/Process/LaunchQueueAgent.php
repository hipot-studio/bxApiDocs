<?php

namespace Bitrix\Sign\Agent\Process;

use Bitrix\Main\Application;
use Bitrix\Sign\Service\Container;
use Throwable;

/**
 * Background of the launch queue: it serves a batch of waiting rows per call and marks the rows of the
 * groups whose waiting term has run out. The queue is drained by an agent because the fon of this module
 * is built on agents; a step per row would never fit into the waiting term of a launch on a department.
 */
final class LaunchQueueAgent
{
	private const LOCK_NAME = 'sign_process_launch_queue';

	public static function getName(): string
	{
		return self::class . '::run();';
	}

	/**
	 * The step is guarded by a named lock: two overlapping calls would take the same waiting rows and start
	 * a second process for the same employee. The step is skipped, not delayed, when the lock is held - the
	 * rows stay in the queue and the next call serves them.
	 *
	 * The term of the groups is checked before the queue is drained, so a row whose group has already been
	 * declared failed does not start behind the back of the initiator.
	 */
	public static function run(): string
	{
		$connection = Application::getConnection();
		if (!$connection->lock(self::LOCK_NAME))
		{
			return self::getName();
		}

		try
		{
			$launchService = Container::instance()->getProcessLaunchService();
			$launchService->expireOutdated();
			$launchService->drainQueue();
		}
		catch (Throwable $exception)
		{
			Container::instance()->getLogger('Agent')->error(
				'LaunchQueueAgent: {message}',
				['message' => $exception->getMessage()],
			);
		}
		finally
		{
			$connection->unlock(self::LOCK_NAME);
		}

		return self::getName();
	}
}
