<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\RoleFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Public\Service\Container as HrPublicContainer;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;

class DeputyStructureGateway
{
	private const PEER_HEADS_CACHE_TTL = 3600;
	private const PEER_HEADS_CACHE_DIR = '/stafftrack/subordinate_scope/peer_heads';
	private const HR_STRUCTURE_CACHE_TAG = 'intranet_department_structure';

	/** @var array<int, array<string, int[]>> */
	private array $departmentIdsMemo = [];
	/** the generation of the structure the departments are remembered in; null when it is not known */
	private ?string $memoGeneration = null;
	private int $failureCount = 0;
	private ?StructureGeneration $structureGeneration = null;

	/**
	 * @return int[]
	 */
	public function getSubordinateIds(int $userId, bool $direct): array
	{
		if ($userId <= 0 || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		try
		{
			return IdNormalizer::normalize(
				HrPublicContainer::getUserDepartmentService()->getSubordinateUserIds(
					$userId,
					$direct,
				),
			);
		}
		catch (\Throwable $e)
		{
			$this->failureCount++;
			self::logException($e, 'getSubordinateIds');

			return [];
		}
	}

	/** @return int[] */
	public function getDepartmentIdsWhereUserIsHead(int $userId): array
	{
		return $this->getDepartmentIdsByRole($userId, NodeMemberRole::Head);
	}

	/** @return int[] */
	public function getDepartmentIdsWhereUserIsDeputy(int $userId): array
	{
		return $this->getDepartmentIdsByRole($userId, NodeMemberRole::DeputyHead);
	}

	/** @return int[] */
	public function getPeerHeadIds(int $userId): array
	{
		if ($userId <= 0 || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		$generation = $this->getStructureGeneration()->acquire();
		$this->forgetReadsOfAnotherGeneration($generation);

		$cached = $this->readPeerHeadIds($userId, $generation);
		if ($cached !== null)
		{
			return $cached;
		}

		try
		{
			$peerHeadIds = IdNormalizer::normalize($this->loadPeerHeadIds($userId));
		}
		catch (\Throwable $e)
		{
			$this->failureCount++;
			self::logException($e, 'getPeerHeadIds');

			return [];
		}

		$this->writePeerHeadIds($userId, $generation, $peerHeadIds);

		return $peerHeadIds;
	}

	/**
	 * @param int[] $departmentIds
	 * @param NodeMemberRole[] $roles
	 * @return int[]
	 */
	public function getMemberIds(array $departmentIds, array $roles, DepthLevel $depthLevel): array
	{
		$departmentIds = IdNormalizer::normalize($departmentIds);
		if (empty($departmentIds) || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		try
		{
			$members = $this->loadMemberIds($departmentIds, $roles, $depthLevel);
		}
		catch (\Throwable $e)
		{
			$this->failureCount++;
			self::logException($e, 'getMemberIds');

			return [];
		}

		return IdNormalizer::normalize($members);
	}

	/**
	 * Returns how many reads of humanresources this gateway answered empty because they failed.
	 */
	public function getFailureCount(): int
	{
		return $this->failureCount;
	}

	/**
	 * Forgets the reads of humanresources the gateway remembers unless they were made in the generation of the
	 * structure given: read before a reset, they would be kept with the token of the generation after it.
	 */
	public function forgetReadsOfAnotherGeneration(?string $generation): void
	{
		if ($generation === $this->memoGeneration)
		{
			return;
		}

		$this->departmentIdsMemo = [];
		$this->memoGeneration = $generation;
	}

	/**
	 * Forgets the departments the gateway remembers of the user: his next reads ask humanresources again.
	 */
	public function forgetReadsAbout(int $userId): void
	{
		unset($this->departmentIdsMemo[$userId]);
	}

	protected function getStructureGeneration(): StructureGeneration
	{
		return $this->structureGeneration ??= new StructureGeneration();
	}

	/** @return int[] */
	private function getDepartmentIdsByRole(int $userId, NodeMemberRole $role): array
	{
		if ($userId <= 0 || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		try
		{
			return $this->loadDepartmentIdsByRoleOnce($userId, $role);
		}
		catch (\Throwable $e)
		{
			$this->failureCount++;
			self::logException($e, 'getDepartmentIdsByRole');

			return [];
		}
	}

	/** @return int[] */
	protected function loadDepartmentIdsByRole(int $userId, NodeMemberRole $role): array
	{
		$service = HrPublicContainer::getUserDepartmentService();

		return match ($role)
		{
			NodeMemberRole::Head => $service->getDepartmentIdsWhereUserIsHead($userId),
			NodeMemberRole::DeputyHead => $service->getDepartmentIdsWhereUserIsDeputy($userId),
			default => [],
		};
	}

	/** @return int[] */
	protected function loadPeerHeadIds(int $userId): array
	{
		$headDepartmentIds = $this->loadDepartmentIdsByRoleOnce($userId, NodeMemberRole::Head);
		if (empty($headDepartmentIds))
		{
			return [];
		}

		return $this->loadMemberIds(
			$headDepartmentIds,
			[NodeMemberRole::Head],
			DepthLevel::NONE,
		);
	}

	/**
	 * @param int[] $departmentIds
	 * @param NodeMemberRole[] $roles
	 * @return int[]
	 */
	protected function loadMemberIds(array $departmentIds, array $roles, DepthLevel $depthLevel): array
	{
		return NodeMemberDataBuilder::createWithFilter(
			new NodeMemberFilter(
				entityType: MemberEntityType::USER,
				nodeFilter: new NodeFilter(
					idFilter: IdFilter::fromIds($departmentIds),
					entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::DEPARTMENT),
					depthLevel: $depthLevel,
				),
				roleFilter: empty($roles) ? null : RoleFilter::fromRoles(...$roles),
			),
		)
			->getAll()
			->getEntityIds()
		;
	}

	/** @return int[] */
	private function loadDepartmentIdsByRoleOnce(int $userId, NodeMemberRole $role): array
	{
		$roleKey = $role->value;
		if (array_key_exists($roleKey, $this->departmentIdsMemo[$userId] ?? []))
		{
			return $this->departmentIdsMemo[$userId][$roleKey];
		}

		return $this->departmentIdsMemo[$userId][$roleKey] = IdNormalizer::normalize(
			$this->loadDepartmentIdsByRole($userId, $role),
		);
	}

	/** @return int[]|null null unless the peer heads are kept for the generation */
	private function readPeerHeadIds(int $userId, ?string $generation): ?array
	{
		if ($generation === null)
		{
			return null;
		}

		try
		{
			$cache = Cache::createInstance();
			$isStored = $cache->initCache(
				self::PEER_HEADS_CACHE_TTL,
				$this->getPeerHeadsCacheId($userId),
				self::PEER_HEADS_CACHE_DIR,
			);
			$stored = $isStored ? $cache->getVars() : null;
		}
		catch (\Throwable)
		{
			return null;
		}

		$isOfTheGeneration = is_array($stored)
			&& ($stored['generation'] ?? null) === $generation
			&& is_array($stored['userIds'] ?? null)
		;

		return $isOfTheGeneration ? IdNormalizer::normalize($stored['userIds']) : null;
	}

	/** @param int[] $userIds */
	private function writePeerHeadIds(int $userId, ?string $generation, array $userIds): void
	{
		if ($generation === null)
		{
			return;
		}

		try
		{
			$cache = Cache::createInstance();
			$cache->noOutput();
			// the peer heads of an earlier generation are replaced before their hour is over
			$cache->forceRewriting(true);

			$isStarted = $cache->startDataCache(
				self::PEER_HEADS_CACHE_TTL,
				$this->getPeerHeadsCacheId($userId),
				self::PEER_HEADS_CACHE_DIR,
			);
			if (!$isStarted)
			{
				return;
			}

			$taggedCache = Application::getInstance()->getTaggedCache();
			$taggedCache->startTagCache(self::PEER_HEADS_CACHE_DIR);
			$taggedCache->registerTag(self::HR_STRUCTURE_CACHE_TAG);
			$taggedCache->endTagCache();

			$cache->endDataCache([
				'generation' => $generation,
				'userIds' => $userIds,
			]);
		}
		catch (\Throwable)
		{
			// the next build loads the peer heads again
		}
	}

	private function getPeerHeadsCacheId(int $userId): string
	{
		return 'stafftrack.peer_heads.v2.' . $userId;
	}

	private static function logException(\Throwable $e, string $method): void
	{
		AddMessage2Log(
			'DeputyStructureGateway::' . $method . ' error: ' . $e->getMessage(),
			'stafftrack',
		);
	}
}
