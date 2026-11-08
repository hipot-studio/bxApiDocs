<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;

/**
 * Which people of a node the structure data node asks for. The values are stored in saved workflow
 * templates.
 */
enum StructureMemberScope: string
{
	case None = 'none';
	case Heads = 'heads';
	case HeadsAndDeputies = 'heads_deputies';
	case Members = 'members';
	case ByRole = 'by_role';

	/**
	 * Roles to read for the node types asked for, in the order of {@see self::rolesOfNodeTypes()}. None
	 * asks for nobody and ByRole takes its roles from a setting of its own, so both give an empty set.
	 *
	 * @param list<NodeEntityType> $nodeTypes
	 * @return list<NodeMemberRole>
	 */
	public function roles(array $nodeTypes): array
	{
		$allowed = self::rolesOfNodeTypes($nodeTypes);

		return match ($this)
		{
			self::None, self::ByRole => [],
			self::Heads => self::keepRoles($allowed, [NodeMemberRole::Head, NodeMemberRole::TeamHead]),
			self::HeadsAndDeputies => self::keepRoles(
				$allowed,
				[
					NodeMemberRole::Head,
					NodeMemberRole::DeputyHead,
					NodeMemberRole::TeamHead,
					NodeMemberRole::TeamDeputyHead,
				],
			),
			self::Members => $allowed,
		};
	}

	/**
	 * Roles the node types hold, without repeats and in the order of the dictionary - departments first,
	 * whatever order the kinds were named in: the order decides how the people of the answer are grouped.
	 *
	 * @param list<NodeEntityType> $nodeTypes
	 * @return list<NodeMemberRole>
	 */
	public static function rolesOfNodeTypes(array $nodeTypes): array
	{
		$named = [];
		foreach ($nodeTypes as $nodeType)
		{
			foreach (NodeMemberRole::rolesForNodeType($nodeType) as $role)
			{
				$named[$role->value] = true;
			}
		}

		return array_values(array_filter(
			NodeMemberRole::cases(),
			static fn(NodeMemberRole $role): bool => isset($named[$role->value]),
		));
	}

	/**
	 * @param list<NodeMemberRole> $allowed
	 * @param list<NodeMemberRole> $wanted
	 * @return list<NodeMemberRole>
	 */
	private static function keepRoles(array $allowed, array $wanted): array
	{
		return array_values(
			array_filter($allowed, static fn(NodeMemberRole $role): bool => in_array($role, $wanted, true)),
		);
	}
}
