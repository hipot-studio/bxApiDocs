<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Feature;

class SubordinateResolver
{
	private DeputyStructureGateway $gateway;
	private TeamScopeCache $teamCache;
	private StructureGeneration $structureGeneration;

	/** @var array<int, array<string, array{userIds: int[], isComplete: bool}>> */
	private array $memo = [];
	/** @var array<int, array<string, TeamScope>> teams read from the team cache, which resolve() never answers with */
	private array $cachedTeamMemo = [];
	/** @var array<int, int[]> */
	private array $headPeerIdsMemo = [];
	/** the generation of the structure the memory of the resolver is filled in; null when it is not known */
	private ?string $memoGeneration = null;

	public function __construct(
		?DeputyStructureGateway $gateway = null,
		?TeamScopeCache $teamCache = null,
		?StructureGeneration $generation = null,
	)
	{
		$this->gateway = $gateway ?? new DeputyStructureGateway();
		$this->teamCache = $teamCache ?? new TeamScopeCache();
		$this->structureGeneration = $generation ?? new StructureGeneration();
	}

	/**
	 * @return int[]
	 */
	public function resolve(int $userId, bool $direct): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		return $this->resolveScope($userId, $direct, Feature::isCheckInDeputyAccessEnabled())['userIds'];
	}

	/**
	 * Returns the team of the user without a filter: the scope resolve() gives for direct, kept between processes
	 * for the generation of the structure and the value of the deputy flag it was built with. A team that is not
	 * whole - a read of the structure behind it failed - is given but not kept. Without a generation - the cache gives
	 * none - the team is read from humanresources at every call: no reset would make the process read again a team
	 * it remembered.
	 */
	public function resolveTeam(int $userId): TeamScope
	{
		if (!Loader::includeModule('humanresources'))
		{
			return new TeamScope([], true, false);
		}

		$isDeputyAccessEnabled = Feature::isCheckInDeputyAccessEnabled();
		$memoKey = self::getMemoKey(true, $isDeputyAccessEnabled);
		$rememberedTeam = $this->getRememberedTeam($userId, $memoKey);
		if ($rememberedTeam !== null)
		{
			return $rememberedTeam;
		}

		// taken before the structure is read: a team built across a reset is kept for the generation it outlived
		$generation = $this->structureGeneration->acquire();
		$this->forgetMemoOfAnotherGeneration($generation);
		if ($generation === null)
		{
			$this->forgetReadsOfTheTeam($userId, $memoKey);

			return self::createTeam($this->resolveScope($userId, true, $isDeputyAccessEnabled));
		}

		$cachedUserIds = $this->teamCache->read($userId, $isDeputyAccessEnabled, $generation);
		if ($cachedUserIds !== null)
		{
			return $this->cachedTeamMemo[$userId][$memoKey] = new TeamScope($cachedUserIds, true, true);
		}

		$scope = $this->resolveScope($userId, true, $isDeputyAccessEnabled);
		if ($scope['isComplete'])
		{
			$this->teamCache->write($userId, $isDeputyAccessEnabled, $generation, $scope['userIds']);
		}

		return self::createTeam($scope);
	}

	/**
	 * @return int[]
	 */
	public function resolvePeerHeadIds(int $userId): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		return array_values(
			array_filter(
				IdNormalizer::normalize($this->resolveHeadPeerScope($userId)),
				static fn (int $id) => $id !== $userId,
			),
		);
	}

	/**
	 * The team the resolver already knows - built by it, or else read from the team cache - while the generation of
	 * the structure it was remembered in is the live one. After a reset, made by this process or another one, the team
	 * is read again, as it is when no generation vouches for it.
	 */
	private function getRememberedTeam(int $userId, string $memoKey): ?TeamScope
	{
		$builtTeam = $this->memo[$userId][$memoKey] ?? null;
		$cachedTeam = $this->cachedTeamMemo[$userId][$memoKey] ?? null;
		if ($builtTeam === null && $cachedTeam === null)
		{
			return null;
		}

		if ($this->memoGeneration === null || $this->memoGeneration !== $this->structureGeneration->current())
		{
			return null;
		}

		return $builtTeam === null ? $cachedTeam : self::createTeam($builtTeam);
	}

	/**
	 * Forgets what the resolver and its gateway remember of the structure unless it was read in the generation given:
	 * read before a reset, it would be kept as the team of the generation after it.
	 */
	private function forgetMemoOfAnotherGeneration(?string $generation): void
	{
		$this->gateway->forgetReadsOfAnotherGeneration($generation);
		if ($generation === $this->memoGeneration)
		{
			return;
		}

		$this->memo = [];
		$this->cachedTeamMemo = [];
		$this->headPeerIdsMemo = [];
		$this->memoGeneration = $generation;
	}

	/**
	 * Forgets what the resolver and its gateway remember of the structure behind the team of the user - the scope,
	 * the peer heads and the departments of the user - so that the team is read from the structure as it is now.
	 */
	private function forgetReadsOfTheTeam(int $userId, string $memoKey): void
	{
		unset($this->memo[$userId][$memoKey], $this->headPeerIdsMemo[$userId]);
		$this->gateway->forgetReadsAbout($userId);
	}

	/**
	 * @return array{userIds: int[], isComplete: bool} not complete when a read of the structure failed in the build
	 */
	private function resolveScope(int $userId, bool $direct, bool $isDeputyAccessEnabled): array
	{
		$memoKey = self::getMemoKey($direct, $isDeputyAccessEnabled);
		if (isset($this->memo[$userId][$memoKey]))
		{
			return $this->memo[$userId][$memoKey];
		}

		$failureCount = $this->gateway->getFailureCount();

		$userIds = $this->gateway->getSubordinateIds($userId, $direct);
		$userIds = [...$userIds, ...$this->resolveHeadPeerScope($userId)];

		if ($isDeputyAccessEnabled)
		{
			$headDepartmentIds = $this->gateway->getDepartmentIdsWhereUserIsHead($userId);
			$deputyDepartmentIds = array_diff(
				$this->gateway->getDepartmentIdsWhereUserIsDeputy($userId),
				$headDepartmentIds,
			);
			$userIds = [...$userIds, ...$this->resolveManagedScope($deputyDepartmentIds, $direct)];
		}

		$userIds = array_values(
			array_filter(
				IdNormalizer::normalize($userIds),
				static fn (int $id) => $id !== $userId,
			),
		);

		$this->memo[$userId][$memoKey] = [
			'userIds' => $userIds,
			'isComplete' => $this->gateway->getFailureCount() === $failureCount,
		];

		return $this->memo[$userId][$memoKey];
	}

	/**
	 * @return int[]
	 */
	private function resolveHeadPeerScope(int $userId): array
	{
		if (array_key_exists($userId, $this->headPeerIdsMemo))
		{
			return $this->headPeerIdsMemo[$userId];
		}

		$failureCount = $this->gateway->getFailureCount();
		$peerHeadIds = $this->gateway->getPeerHeadIds($userId);

		// a failed read is answered empty: remembered, it would pass for no peer heads in a whole team
		if ($this->gateway->getFailureCount() === $failureCount)
		{
			$this->headPeerIdsMemo[$userId] = $peerHeadIds;
		}

		return $peerHeadIds;
	}

	/**
	 * @return int[]
	 */
	private function resolveManagedScope(array $departmentIds, bool $direct): array
	{
		$departmentIds = IdNormalizer::normalize($departmentIds);
		if (empty($departmentIds))
		{
			return [];
		}

		if (!$direct)
		{
			return $this->gateway->getMemberIds($departmentIds, [], DepthLevel::FULL);
		}

		return [
			...$this->gateway->getMemberIds(
				$departmentIds,
				[NodeMemberRole::Head, NodeMemberRole::DeputyHead, NodeMemberRole::Employee],
				DepthLevel::NONE,
			),
			...$this->gateway->getMemberIds($departmentIds, [NodeMemberRole::Head], DepthLevel::FIRST),
		];
	}

	private static function getMemoKey(bool $direct, bool $isDeputyAccessEnabled): string
	{
		return (int)$direct . ':' . (int)$isDeputyAccessEnabled;
	}

	/**
	 * @param array{userIds: int[], isComplete: bool} $scope
	 */
	private static function createTeam(array $scope): TeamScope
	{
		return new TeamScope($scope['userIds'], $scope['isComplete'], false);
	}
}
