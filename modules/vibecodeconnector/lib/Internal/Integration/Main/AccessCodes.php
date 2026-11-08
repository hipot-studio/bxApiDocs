<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Main;

use Bitrix\Main\UserTable;
use Bitrix\Vibecodeconnector\Internal\Exception\LiveDepartmentAccessUnavailableException;
use Bitrix\Vibecodeconnector\Internal\Integration\HumanResources\DepartmentCodesCache;
use Bitrix\Vibecodeconnector\Internal\Integration\HumanResources\StructureGate;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\AccessRepository;
use CAccess;

/**
 * Wrapper over the main access API (`\CAccess`).
 * Returns the user's access codes (U<id>, G<id>, D<id>, AU, ...).
 */
class AccessCodes
{
	public const ACCESS_CODE_AUTHORIZED = 'AU';
	private const USER_CHUNK_SIZE = 2000;
	private const AUDIT_TYPE_UNSUPPORTED_CODES = 'VIBECODECONNECTOR_UNSUPPORTED_ACCESS_CODES';

	private ?StructureGate $structureGate = null;
	private ?DepartmentCodesCache $departmentCodesCache = null;
	/** @var array<string, true> */
	private array $reportedUnsupported = [];

	public function __construct(?DepartmentCodesCache $departmentCodesCache = null)
	{
		$this->departmentCodesCache = $departmentCodesCache;
	}

	/**
	 * @return string[]
	 */
	public function getUserCodes(int $userId): array
	{
		return $this->getUserCodeResult($userId)->codes;
	}

	public function getUserCodeResult(int $userId): UserCodeResult
	{
		return $this->assembleUserCodes(
			$userId,
			fn (): array => $this->confirmedDepartmentCodes($userId),
		);
	}

	public function getUserCodesForItems(int $userId, array $catalogItemIds): array
	{
		$catalogItemIds = $this->normalizeUserIds($catalogItemIds);

		return $this->assembleUserCodes($userId, function () use ($userId, $catalogItemIds): array {
			if ($catalogItemIds === [] || !$this->structureGate()->isAvailable())
			{
				return [];
			}

			$codesByItem = (new AccessRepository())->getCodesByItemIds($catalogItemIds);
			$candidates = array_values(array_unique(array_merge([], ...array_values($codesByItem))));
			$nodes = $this->structureGate()->mapDepartmentCodesToNodes($candidates);

			return $this->structureGate()->confirmDepartmentCodes($userId, $nodes);
		})->codes;
	}

	private function assembleUserCodes(int $userId, callable $resolveLiveCodes): UserCodeResult
	{
		$codes = $this->prepareStoredUserCodes($userId);
		if ($codes === null)
		{
			return new UserCodeResult([], false);
		}

		$degraded = false;
		try
		{
			$liveCodes = $resolveLiveCodes();
		}
		catch (LiveDepartmentAccessUnavailableException)
		{
			$liveCodes = [];
			$degraded = true;
		}

		$knownCodes = array_fill_keys($codes, true);
		foreach ($liveCodes as $code)
		{
			if (!isset($knownCodes[$code]))
			{
				$codes[] = $code;
				$knownCodes[$code] = true;
			}
		}

		$codes[] = self::ACCESS_CODE_AUTHORIZED;

		return new UserCodeResult($codes, $degraded);
	}

	public function getStoredUserCodes(int $userId): array
	{
		$codes = $this->prepareStoredUserCodes($userId);

		return $codes === null ? [] : array_merge($codes, [self::ACCESS_CODE_AUTHORIZED]);
	}

	public function filterLiveDepartmentMembers(array $userIds, array $codes): array
	{
		return $this->structureGate()->filterDepartmentMembers($userIds, $codes);
	}

	private function prepareStoredUserCodes(int $userId): ?array
	{
		if ($userId <= 0)
		{
			return null;
		}

		$codes = array_values(array_unique($this->fetchStoredCodes($userId)));
		if ($codes === [] && $this->filterExistingUsers([$userId]) === [])
		{
			return null;
		}

		return array_values(array_filter(
			$codes,
			static fn (string $code): bool => $code !== self::ACCESS_CODE_AUTHORIZED,
		));
	}

	public static function grantsEveryone(array $codes): bool
	{
		return in_array(self::ACCESS_CODE_AUTHORIZED, $codes, true);
	}

	public function canResolveAudience(array $codes): bool
	{
		return AudienceCodeSet::fromCodes($codes)->namesNoStructure() || $this->structureGate()->isAvailable();
	}

	public function resolveCodeHolders(array $userIds, array $codes): CodeHolders
	{
		$userIds = $this->normalizeUserIds($userIds);
		if ($userIds === [] || $codes === [])
		{
			return new CodeHolders([]);
		}

		$audience = AudienceCodeSet::fromCodes($codes);
		$this->reportUnsupported($audience, 'resolveCodeHolders');

		if ($audience->everyEmployee)
		{
			return new CodeHolders(array_values(array_intersect($userIds, $this->filterExistingUsers($userIds))));
		}

		$holders = array_fill_keys($audience->userIds, true)
			+ array_fill_keys($this->structureGate()->filterMembers($userIds, $audience), true);

		return new CodeHolders(
			array_values(array_filter($userIds, static fn (int $userId): bool => isset($holders[$userId]))),
		);
	}

	/**
	 * @return int[]
	 */
	public function getUserIdsByCodes(array $codes, int $limit, int $afterUserId = 0): array
	{
		$codes = array_values(array_filter(
			$codes,
			static fn (mixed $code): bool => is_string($code) && trim($code) !== '',
		));
		if ($codes === [] || $limit <= 0)
		{
			return [];
		}

		$audience = AudienceCodeSet::fromCodes($codes);
		$this->reportUnsupported($audience, 'getUserIdsByCodes');

		$userIds = array_fill_keys(
			array_filter($audience->userIds, static fn (int $userId): bool => $userId > $afterUserId),
			true,
		);
		$userIds += array_fill_keys(
			$this->structureGate()->enumerateMembers($audience, $limit, $afterUserId),
			true,
		);

		$userIds = array_keys($userIds);
		sort($userIds, SORT_NUMERIC);

		return array_slice($userIds, 0, $limit);
	}

	/**
	 * Codes materialized in b_user_access. Seam for tests.
	 *
	 * @return string[]
	 */
	protected function fetchStoredCodes(int $userId): array
	{
		return CAccess::getUserCodesArray($userId);
	}

	/**
	 * @return int[]
	 */
	protected function filterExistingUsers(array $userIds): array
	{
		$existing = [];
		foreach (array_chunk($userIds, self::USER_CHUNK_SIZE) as $chunk)
		{
			$rows = UserTable::query()
				->setSelect(['ID'])
				->whereIn('ID', $chunk)
				->fetchAll()
			;
			$existing = array_merge($existing, array_column($rows, 'ID'));
		}

		return array_map('intval', $existing);
	}

	protected function structureGate(): StructureGate
	{
		return $this->structureGate ??= new StructureGate();
	}

	/**
	 * @return array<string, int[]>
	 */
	protected function departmentCodeNodes(): array
	{
		$this->departmentCodesCache ??= new DepartmentCodesCache();

		return $this->structureGate()->mapDepartmentCodesToNodes(
			$this->departmentCodesCache->remember(
				fn (): array => (new AccessRepository())->getDistinctDepartmentCodes(),
			),
		);
	}

	/**
	 * @return string[]
	 */
	private function confirmedDepartmentCodes(int $userId): array
	{
		if (!$this->structureGate()->isAvailable())
		{
			return [];
		}

		return $this->structureGate()->confirmDepartmentCodes($userId, $this->departmentCodeNodes());
	}

	/**
	 * @return int[]
	 */
	private function normalizeUserIds(array $userIds): array
	{
		return array_values(array_filter(
			array_unique(array_map('intval', $userIds)),
			static fn (int $userId): bool => $userId > 0,
		));
	}

	private function reportUnsupported(AudienceCodeSet $audience, string $context): void
	{
		if ($audience->unsupported === [])
		{
			return;
		}

		$unsupported = $audience->unsupported;
		sort($unsupported);
		$signature = $context . ':' . implode(',', $unsupported);
		if (isset($this->reportedUnsupported[$signature]))
		{
			return;
		}
		$this->reportedUnsupported[$signature] = true;

		\CEventLog::Log(
			\CEventLog::SEVERITY_WARNING,
			self::AUDIT_TYPE_UNSUPPORTED_CODES,
			'vibecodeconnector',
			$context,
			implode(', ', $unsupported),
		);
	}
}
