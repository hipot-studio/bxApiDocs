<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Type;

/**
 * Node member roles.
 *
 * Usage:
 * ```
 * NodeMemberRole::Head->value              // 'MEMBER_HEAD'
 * NodeMemberRole::TeamHead->value          // 'MEMBER_TEAM_HEAD'
 * NodeMemberRole::departmentRoles()        // [Head, DeputyHead, Employee]
 * NodeMemberRole::teamRoles()              // [TeamHead, TeamDeputyHead, TeamEmployee]
 * ```
 */
enum NodeMemberRole: string
{
	case Head = 'MEMBER_HEAD';
	case DeputyHead = 'MEMBER_DEPUTY_HEAD';
	case Employee = 'MEMBER_EMPLOYEE';
	case TeamHead = 'MEMBER_TEAM_HEAD';
	case TeamDeputyHead = 'MEMBER_TEAM_DEPUTY_HEAD';
	case TeamEmployee = 'MEMBER_TEAM_EMPLOYEE';

	/**
	 * @return self[]
	 */
	public static function departmentRoles(): array
	{
		return [
			self::Head,
			self::DeputyHead,
			self::Employee,
		];
	}

	/**
	 * @return self[]
	 */
	public static function teamRoles(): array
	{
		return [
			self::TeamHead,
			self::TeamDeputyHead,
			self::TeamEmployee,
		];
	}

	/**
	 * @return self[]
	 */
	public static function rolesForNodeType(NodeEntityType $type): array
	{
		return $type === NodeEntityType::TEAM
			? self::teamRoles()
			: self::departmentRoles()
		;
	}

	/**
	 * @return string[]
	 */
	public static function allowedValuesForNodeType(NodeEntityType $type): array
	{
		return array_map(fn(self $r) => $r->value, self::rolesForNodeType($type));
	}

	public static function defaultForNodeType(NodeEntityType $type): self
	{
		return $type === NodeEntityType::TEAM
			? self::TeamEmployee
			: self::Employee
		;
	}

	/**
	 * Converts XML ID strings to NodeMemberRole instances, skipping invalid values.
	 *
	 * @param string[] $xmlIds
	 *
	 * @return self[]
	 */
	public static function fromXmlIds(array $xmlIds): array
	{
		$roles = [];
		foreach ($xmlIds as $xmlId)
		{
			$role = self::tryFrom($xmlId);
			if ($role !== null)
			{
				$roles[] = $role;
			}
		}

		return $roles;
	}
}
