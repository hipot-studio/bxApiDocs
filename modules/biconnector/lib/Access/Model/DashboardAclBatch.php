<?php

namespace Bitrix\BIConnector\Access\Model;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;

/** @internal One chart admission; neither permissions nor policy decisions are memoized. */
final class DashboardAclBatch
{
	public const CHUNK_SIZE = 300;

	private readonly DashboardAdmissionEnvironment $environment;
	private bool $legacy = false;
	private bool $ready = false;
	private bool $scanMissing = false;
	private bool $checkRightsFilters = false;
	private bool $subscriptionReached = false;
	private bool $marketReached = false;
	private bool $planReached = false;
	private array $ids = [];
	private array $rows = [];
	private array $allowedIds = [];

	public function __construct(private readonly int $userId)
	{
		$this->environment = new DashboardAdmissionEnvironment();
	}

	public function isForUser(int $userId): bool
	{
		return $this->userId === $userId;
	}

	public function isLegacy(): bool
	{
		return $this->legacy;
	}

	public function refresh(): void
	{
		if ($this->legacy)
		{
			return;
		}
		if (
			$this->environment->hasAccessHandlers()
			|| ($this->checkRightsFilters && !$this->environment->hasSupportedFeatures(['bi_constructor_rights']))
			|| ($this->planReached && !$this->environment->hasSupportedFeatures([
				'bi_constructor',
				'bi_constructor_external_entities',
			]))
			|| ($this->marketReached && $this->environment->hasMarketTrialExtensions())
		)
		{
			$this->useLegacy();
		}
	}

	public function canBatch(): bool
	{
		$this->refresh();

		return !$this->legacy && $this->ready;
	}

	public function canScanMissing(): bool
	{
		return !$this->legacy && $this->scanMissing;
	}

	public function observeMissing(): void
	{
		$this->scanMissing = true;
	}

	public function observeAccess(bool $isAdmin): void
	{
		$this->scanMissing = false;
		$this->checkRightsFilters = !$isAdmin;
		$this->refresh();
		$this->ready = !$this->legacy;
	}

	public function discardRows(): void
	{
		$this->ids = [];
		$this->rows = [];
		$this->allowedIds = [];
	}

	public function observeNext(): void
	{
		$this->discardRows();
		$this->ready = false;
		$this->scanMissing = false;
	}

	private function useLegacy(): void
	{
		$this->legacy = true;
		$this->observeNext();
	}

	/** @param list<int> $ids At most one local chunk, in mapping order. */
	public function loadRows(array $ids): void
	{
		$this->discardRows();
		$this->ids = $ids;
		$rows = SupersetDashboardTable::query()
			->setSelect(['ID', 'EXTERNAL_ID', 'TYPE', 'STATUS', 'APP_ID'])
			->whereIn('ID', $ids)
			->exec()
		;
		while ($row = $rows->fetch())
		{
			$this->rows[(int)$row['ID']] = $row;
		}
	}

	public function contains(int $id): bool
	{
		return in_array($id, $this->ids, true);
	}

	public function getRow(int $id): ?array
	{
		return $this->rows[$id] ?? null;
	}

	public function getAllowedIds(
		string $ruleClass,
		string $permission,
		array $groups,
		int $id,
		\Closure $load,
	): ?array
	{
		if (!$this->canBatch() || !$this->contains($id))
		{
			return null;
		}

		$key = serialize([$this->userId, $ruleClass, $permission, $groups, $this->ids]);
		if (!array_key_exists($key, $this->allowedIds))
		{
			$this->allowedIds[$key] = array_fill_keys($load($this->ids), true);
		}

		return isset($this->allowedIds[$key][$id]) ? [$id] : [];
	}

	public function beforeSubscription(string $type): void
	{
		$isMarket = $type === SupersetDashboardTable::DASHBOARD_TYPE_MARKET;
		if (!$this->subscriptionReached || ($isMarket && !$this->marketReached))
		{
			$this->observeNext();
		}
		if ($isMarket && $this->environment->hasMarketTrialExtensions())
		{
			$this->useLegacy();
		}
	}

	public function observeMarketCall(?bool $accessible, bool $availabilityReached): void
	{
		if ($accessible === true && $availabilityReached)
		{
			if (!$this->environment->canRepeatMarketTrialRead())
			{
				$this->useLegacy();
			}
		}
	}

	public function afterSubscription(string $type): void
	{
		$this->subscriptionReached = true;
		$this->marketReached = $this->marketReached || $type === SupersetDashboardTable::DASHBOARD_TYPE_MARKET;
		$this->refresh();
	}

	public function beforeSystemPlan(): void
	{
		if (!$this->planReached)
		{
			$this->observeNext();
		}
	}

	public function afterSystemPlan(): void
	{
		$this->planReached = true;
		$this->refresh();
	}
}
