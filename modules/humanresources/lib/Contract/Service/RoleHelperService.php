<?php

namespace Bitrix\HumanResources\Contract\Service;

use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Type\NodeMemberRole;

interface RoleHelperService
{
	public function getById(int $roleId): ?Item\Role;
	public function getEmployeeRoleId(): ?int;
	public function getHeadRoleId(): ?int;
	public function getDeputyRoleId(): ?int;
	public function getTeamHeadRoleId(): ?int;
	public function getTeamDeputyRoleId(): ?int;
	public function getTeamEmployeeRoleId(): ?int;
	public function getAllRoleCollectionForSync(): Item\Collection\RoleCollection;
	public function getAccessCodeByRoleXmlId(string $xmlId): ?string;

	/**
	 * Returns a map of department role IDs to their corresponding NodeMemberRole string values.
	 * Role IDs not registered in the DB are excluded.
	 *
	 * @return array<int, string>
	 */
	public function getDepartmentRoleIdToMemberRoleMap(): array;
}