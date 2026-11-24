<?php

namespace Bitrix\AI\Limiter;

use Bitrix\AI\Feature\MonthlyPoolAggregate;
use Bitrix\AI\Model\MonthlyPoolReceiptTable;
use Bitrix\AI\Model\MonthlyPoolStateTable;
use Bitrix\AI\Model\MonthlyPoolTotalTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\Connection;

class SharedMonthlyPoolActivation
{
	private const ACTIVATED_MARKER = '~monthly_pool_activated';

	/** @var null|\WeakMap<Connection, array{scope: object, mode: string}> */
	private static ?\WeakMap $modeCache = null;

	private readonly Connection $connection;

	/**
	 * The release owner supplies independently verified evidence, never inferred from CUTOVER_REF.
	 * @param null|\Closure(string): array{writersStopped: bool, pendingPeriodsProven: bool} $releaseProof
	 */
	public function __construct(?Connection $connection = null, private readonly ?\Closure $releaseProof = null)
	{
		$this->connection = $connection ?? Application::getConnection();
	}

	public function getMode(): string
	{
		$cache = self::$modeCache ??= new \WeakMap();
		$scope = $this->getModeCacheScope();
		$cached = $cache[$this->connection] ?? null;
		if ($cached !== null && $cached['scope'] === $scope)
		{
			return $cached['mode'];
		}

		try
		{
			$this->useMasterOnly(true);
			$activated = $this->wasActivated();
			$state = $this->readState();
			if ($state === null || $state['MODE'] === 'legacy')
			{
				$mode = $activated ? 'closed' : 'legacy';
			}
			else
			{
				$mode = $activated && $state['MODE'] === 'active' && $this->isEnabled() && $this->isSchemaReady()
					? 'aggregate'
					: 'closed'
				;
			}
		}
		catch (\Throwable)
		{
			$mode = 'closed';
		}
		finally
		{
			$this->useMasterOnly(false);
		}

		$cache[$this->connection] = ['scope' => $scope, 'mode' => $mode];

		return $mode;
	}

	public function activate(string $cutoverRef): void
	{
		$this->invalidateModeCache();
		if (trim($cutoverRef) === '' || strlen($cutoverRef) > 255 || $this->releaseProof === null)
		{
			throw new \LogicException('Independent release evidence is required.');
		}

		$locked = false;
		try
		{
			$this->useMasterOnly(true);
			$locked = $this->connection->lock(SharedMonthlyPoolService::LOCK_NAME, 5);
			if (!$locked)
			{
				throw new \RuntimeException('Unable to lock the shared monthly AI pool.');
			}
			$proof = ($this->releaseProof)($cutoverRef);
			if (($proof['writersStopped'] ?? false) !== true || ($proof['pendingPeriodsProven'] ?? false) !== true)
			{
				throw new \LogicException('All old writers and pending original periods must be proven.');
			}
			if (!$this->isEnabled() || !$this->isSchemaReady())
			{
				throw new \LogicException('Monthly pool capability and schema must be ready.');
			}
			$state = $this->readState();
			if (
				$this->wasActivated()
				&& ($state['MODE'] ?? null) === 'active'
				&& ($state['CUTOVER_REF'] ?? null) === $cutoverRef
			)
			{
				return;
			}
			if ($state !== null && $state['MODE'] !== 'legacy')
			{
				throw new \LogicException('An active cutover cannot be replaced implicitly.');
			}
			// Persist the tombstone first: an interrupted cutover stays closed, never legacy.
			$this->markActivated();
			$this->writeActiveState($cutoverRef, (int)($state['EPOCH'] ?? 0) + 1);
		}
		finally
		{
			$this->invalidateModeCache();
			try
			{
				if ($locked)
				{
					$this->connection->unlock(SharedMonthlyPoolService::LOCK_NAME);
				}
			}
			finally
			{
				$this->useMasterOnly(false);
			}
		}
	}

	protected function invalidateModeCache(): void
	{
		if (self::$modeCache !== null)
		{
			unset(self::$modeCache[$this->connection]);
		}
	}

	protected function getModeCacheScope(): object
	{
		return Application::getInstance()->getContext();
	}

	protected function wasActivated(): bool
	{
		return Option::get('ai', self::ACTIVATED_MARKER, 'N') === 'Y';
	}

	protected function markActivated(): void
	{
		Option::set('ai', self::ACTIVATED_MARKER, 'Y');
		if (!$this->wasActivated())
		{
			throw new \RuntimeException('Unable to persist the monthly pool activation tombstone.');
		}
	}

	protected function readState(): ?array
	{
		if (!$this->connection->isTableExists(MonthlyPoolStateTable::getTableName()))
		{
			return null;
		}
		return MonthlyPoolStateTable::getByPrimary(1)->fetch() ?: null;
	}

	protected function isEnabled(): bool
	{
		return Feature::isEnabled(MonthlyPoolAggregate::class);
	}

	protected function isSchemaReady(): bool
	{
		foreach ([MonthlyPoolTotalTable::class, MonthlyPoolReceiptTable::class, MonthlyPoolStateTable::class] as $class)
		{
			if (!$this->connection->isTableExists($class::getTableName()))
			{
				return false;
			}
			$fields = $this->connection->getTableFields($class::getTableName());
			foreach ($class::getMap() as $field)
			{
				if (!isset($fields[$field->getName()]))
				{
					return false;
				}
			}
		}
		return true;
	}

	protected function writeActiveState(string $ref, int $epoch): void
	{
		$data = ['MODE' => 'active', 'EPOCH' => $epoch, 'CUTOVER_REF' => $ref];
		$result = $this->readState() === null
			? MonthlyPoolStateTable::add(['ID' => 1] + $data)
			: MonthlyPoolStateTable::update(1, $data)
		;
		if (!$result->isSuccess())
		{
			throw new \RuntimeException('Unable to persist the shared monthly pool cutover.');
		}
	}

	protected function useMasterOnly(bool $mode): void
	{
		Application::getInstance()->getConnectionPool()->useMasterOnly($mode);
	}
}
