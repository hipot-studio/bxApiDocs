<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Event;

use Bitrix\Main\Application;
use CCalendar;
use Throwable;

final class ExclusionSaveTransaction
{
	private static ?\stdClass $context = null;

	public static function hasNativeManager(): bool
	{
		return method_exists(CCalendar::class, 'runEventSaveTransaction');
	}

	public static function isActive(): bool
	{
		return self::$context !== null
			|| (self::hasNativeManager() && CCalendar::isEventSaveTransactionActive());
	}

	public static function saveParameters(): array
	{
		return self::hasNativeManager() ? ['eventSaveTransactionMode' => 'join_existing'] : [];
	}

	public static function run(callable $callback): mixed
	{
		if (self::isActive())
		{
			throw new \LogicException('Exclusion requires its own transaction.');
		}
		if (self::hasNativeManager())
		{
			return CCalendar::runEventSaveTransaction($callback);
		}

		$connection = Application::getConnection();
		$context = (object)['cleanup' => [], 'effects' => [], 'committed' => false];
		self::$context = $context;
		try
		{
			$connection->startTransaction();
			$result = $callback();
			$connection->commitTransaction();
			$context->committed = true;

			return $result;
		}
		catch (Throwable $exception)
		{
			try
			{
				$connection->rollbackTransaction();
			}
			catch (Throwable $rollbackException)
			{
				self::log($rollbackException, 'rollback');
			}

			throw $exception;
		}
		finally
		{
			self::$context = null;
			self::runActions($context->cleanup, 'cleanup');
			if ($context->committed)
			{
				self::runActions($context->effects, 'post_commit');
			}
		}
	}

	public static function afterCommit(callable $action): void
	{
		if (self::$context !== null)
		{
			self::$context->effects[] = $action;
		}
		elseif (self::hasNativeManager())
		{
			CCalendar::runEventSavePostCommitOrNow($action);
		}
		else
		{
			$action();
		}
	}

	public static function cleanup(callable $action): void
	{
		if (self::$context !== null)
		{
			self::$context->cleanup[] = $action;
		}
		elseif (self::hasNativeManager())
		{
			CCalendar::registerEventSaveTransactionCleanup($action);
		}
		else
		{
			$action();
		}
	}

	private static function runActions(array $actions, string $stage): void
	{
		foreach ($actions as $action)
		{
			try
			{
				$action();
			}
			catch (Throwable $exception)
			{
				self::log($exception, $stage);
			}
		}
	}

	public static function log(Throwable $exception, string $stage): void
	{
		try
		{
			if (function_exists('AddMessage2Log'))
			{
				AddMessage2Log('Calendar exclusion ' . $stage . ': ' . $exception->getMessage(), 'calendar');
			}
		}
		catch (Throwable)
		{
		}
	}
}
