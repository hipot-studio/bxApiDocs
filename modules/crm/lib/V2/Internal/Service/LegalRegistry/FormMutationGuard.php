<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;

/** Serializes public legacy writes and binding commits without changing their database transactions. */
final class FormMutationGuard
{
	private const LOCK_TIMEOUT = 1;
	/** @var \WeakMap<Connection, array<string, int>>|null */
	private static ?\WeakMap $held = null;
	private bool $owned = true;

	private function __construct(private readonly Connection $connection, private readonly string $name) {}

	public static function acquire(int $formId): self
	{
		if ($formId <= 0 || $formId > 2147483647) { throw new \InvalidArgumentException('INVALID_PAYLOAD'); }
		$name = 'crm_legal_form_mutation_' . $formId;
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$connection = Application::getConnection();
			self::$held ??= new \WeakMap();
			$locks = self::$held[$connection] ?? [];
			if ($locks !== [] && !isset($locks[$name]))
			{
				throw new \RuntimeException('REVISION_CONFLICT');
			}
			if (!isset($locks[$name]) && !$connection->lock($name, self::LOCK_TIMEOUT))
			{
				throw new \RuntimeException('REVISION_CONFLICT');
			}
			$locks[$name] = ($locks[$name] ?? 0) + 1;
			self::$held[$connection] = $locks;

			return new self($connection, $name);
		}
		finally { $pool->useMasterOnly(false); }
	}

	public function release(): void
	{
		if (!$this->owned) { return; }
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$locks = self::$held[$this->connection] ?? [];
			$count = $locks[$this->name] ?? 0;
			if ($count <= 0) { throw new \RuntimeException('STORAGE_ERROR'); }
			if ($count > 1)
			{
				$locks[$this->name] = $count - 1;
			}
			else
			{
				unset($locks[$this->name]);
			}
			self::$held[$this->connection] = $locks;
			if ($count === 1 && !$this->connection->unlock($this->name))
			{
				throw new \RuntimeException('STORAGE_ERROR');
			}
		}
		finally
		{
			$this->owned = false;
			$pool->useMasterOnly(false);
		}
	}
}
