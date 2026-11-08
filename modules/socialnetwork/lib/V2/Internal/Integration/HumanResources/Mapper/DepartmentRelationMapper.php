<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Mapper;

use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;

class DepartmentRelationMapper
{
	public function __construct(
		private readonly MemberEntityMapper $memberEntityMapper,
	)
	{
	}

	public function map(object $relation): ?MemberEntity
	{
		if (!isset($relation->node) || $relation->node === null || !isset($relation->withChildNodes))
		{
			return null;
		}

		$type = $this->resolveMemberType($relation->node);
		$id = $type === MemberEntityType::Team
			? $this->resolveNodeId($relation->node)
			: $this->resolveDepartmentId($relation->node)
		;
		if ($id === null)
		{
			return null;
		}

		return new MemberEntity(
			id: $id,
			type: $type,
			withChildNodes: $relation->withChildNodes,
			name: $relation->node->name,
		);
	}

	/**
	 * The intranet department id a node stands for, or null when the node has no counterpart in the legacy
	 * structure and is therefore addressed by its node id alone. Both ids are plain integers coming from
	 * different sequences, so a member id does not tell which of the two it is.
	 */
	public function findLegacyDepartmentId(object $relation): ?int
	{
		$node = $relation->node ?? null;
		if (!is_object($node) || $this->resolveMemberType($node) !== MemberEntityType::Department)
		{
			return null;
		}

		return $this->resolveLegacyDepartmentId($node);
	}

	private function resolveMemberType(object $node): MemberEntityType
	{
		$nodeType = $node->type ?? null;

		return $nodeType instanceof NodeEntityType && $nodeType->isTeam()
			? MemberEntityType::Team
			: MemberEntityType::Department
		;
	}

	private function resolveDepartmentId(object $node): ?int
	{
		return $this->resolveLegacyDepartmentId($node) ?? $this->resolveNodeId($node);
	}

	private function resolveLegacyDepartmentId(object $node): ?int
	{
		$legacyDepartment = is_string($node->accessCode ?? null)
			? $this->memberEntityMapper->fromAccessCode($node->accessCode)
			: null
		;
		if (
			$legacyDepartment?->type === MemberEntityType::Department
			&& $legacyDepartment->id !== null
			&& $legacyDepartment->id > 0
		)
		{
			return $legacyDepartment->id;
		}

		return null;
	}

	private function resolveNodeId(object $node): ?int
	{
		$nodeId = $node->id ?? null;

		return is_numeric($nodeId) && (int)$nodeId > 0 ? (int)$nodeId : null;
	}
}
