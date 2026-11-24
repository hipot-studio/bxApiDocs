<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

/** A monotonic portal marker: disabling editing must not hide previously issued grants. */
final class SharedEditingUsage
{
	public const OPTION = 'vibeoffice_shared_ever_used';

	public static function hasBeenUsed(): bool
	{
		return Option::get('disk', self::OPTION, 'N') === 'Y';
	}

	/** Publish before admission: cache invalidation must follow the autocommitted marker, never precede it. */
	public static function markUsed(): bool
	{
		if (DocumentStateRepository::hasOpenTransaction() || DocumentStateRepository::holdsAnyLock()) { return false; }
		try
		{
			if (self::isPersisted() && self::hasBeenUsed()) { return true; }
			// Rewriting the same value also repairs an old negative Option cache after a committed marker.
			Option::set('disk', self::OPTION, 'Y');
			return self::isPersisted();
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	/** Read the latest committed marker without locking a global row or reusing an outer RR snapshot. */
	public static function isPersistedFresh(): bool
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		$connection = null;
		try
		{
			$primary = Application::getConnection();
			$configuration = array_replace($primary->getConfiguration(), [
				'options' => 0, 'persistent' => false, 'include_after_connected' => '',
			]);
			unset($configuration['initCommand']);
			if ($primary->getType() === 'pgsql')
			{
				$charset = $primary->queryScalar("SELECT current_setting('client_encoding')");
				if (!is_string($charset) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,31}$/D', $charset) !== 1)
				{
					throw new \RuntimeException('The usage connection encoding is unsupported.');
				}
				// A distinct libpq DSN prevents disconnect from closing the caller's primary session.
				$configuration['charset'] = $charset . ' --application_name=disk_vo_usage_' . bin2hex(random_bytes(8));
			}
			$connection = new ($primary::class)($configuration);
			return $connection->queryScalar("SELECT VALUE FROM b_option WHERE MODULE_ID='disk'"
				. " AND NAME='" . self::OPTION . "'") === 'Y';
		}
		finally
		{
			try { $connection?->disconnect(); }
			finally { $pool->useMasterOnly(false); }
		}
	}

	/** Reservation checks durable primary state without publishing options inside its transaction. */
	public static function isPersisted(): bool
	{
		try
		{
			return Application::getConnection()->queryScalar("SELECT VALUE FROM b_option WHERE MODULE_ID='disk'"
				. " AND NAME='" . self::OPTION . "'") === 'Y';
		}
		catch (\Throwable)
		{
			return false;
		}
	}
}
