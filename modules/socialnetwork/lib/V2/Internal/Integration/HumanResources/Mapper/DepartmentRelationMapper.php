<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Mapper;

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

		$departmentId = $this->resolveDepartmentId($relation->node);
		if ($departmentId === null)
		{
			return null;
		}

		return new MemberEntity(
			id: $departmentId,
			type: MemberEntityType::Department,
			withChildNodes: $relation->withChildNodes,
			name: $relation->node->name,
		);
	}

	private function resolveDepartmentId(object $node): ?int
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

		$nodeId = $node->id ?? null;
		if (is_numeric($nodeId))
		{
			$nodeId = (int)$nodeId;

			return $nodeId > 0 ? $nodeId : null;
		}

		return null;
	}
}
