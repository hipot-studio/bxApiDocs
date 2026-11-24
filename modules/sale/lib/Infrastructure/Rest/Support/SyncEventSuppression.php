<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Sale\Rest\Synchronization\Manager;

/**
 * Silences the outgoing synchronization events of an exchange call, and keeps the mark of a plain
 * call from outliving it.
 *
 * The synchronization manager is a singleton per process, so the mark has to be restored on the way
 * out — and to the previous value, not to "nothing": the caller may already be inside an exchange.
 * V1 sets the mark and never clears it, which is why one import silences the plain writes that
 * follow it in a batch. The REST handler of the deletion event does the same with a mark of its own,
 * so a plain deletion silenced the writes after it as well ({@see keepMark()}).
 */
final class SyncEventSuppression
{
	/**
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	public static function run(callable $operation): mixed
	{
		return self::keepMark(static function () use ($operation): mixed
		{
			Manager::getInstance()->setAction(Manager::ACTION_IMPORT);

			return $operation();
		});
	}

	/**
	 * Runs a plain call and puts back the mark it found. Nothing is raised in its place, so the
	 * events of the call go out as in V1; only what the call left behind is dropped — the "deleted"
	 * mark the delete handler raises on its own, as intended.
	 *
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	public static function keepMark(callable $operation): mixed
	{
		$manager = Manager::getInstance();
		$previousAction = $manager->getAction();

		try
		{
			return $operation();
		}
		finally
		{
			$manager->setAction($previousAction);
		}
	}
}
