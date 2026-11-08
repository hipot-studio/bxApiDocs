<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Access;

use Bitrix\Bizproc\Internal\Entity\Access\PermissionCollection;
use Bitrix\Bizproc\Internal\Entity\Access\RoleCollection;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;

/**
 * Single data-access owner of the bizproc ACL tables (b_bp_access_role, b_bp_access_permission,
 * b_bp_access_role_relation). Every consumer reaches these tables through this repository; no other class
 * touches the ORM `*Table` entities directly.
 *
 * The methods are transaction-agnostic: callers that need atomicity (the save service, the installer) open
 * their own transaction/lock around several repository calls.
 *
 * One method reads outside those three tables - {@see self::getUserAccessCodes()}, the membership side of the
 * check. It lives here because it is a data-access read of the same subject and because the alternative was a
 * raw query in a service.
 */
interface AccessRepositoryInterface
{
	/**
	 * Role ids carried by the given access codes (membership). Empty codes yield an empty list.
	 *
	 * @param string[] $accessCodes
	 * @return int[]
	 */
	public function getRoleIdsByAccessCodes(array $accessCodes): array;

	/**
	 * All roles ordered by id.
	 */
	public function getAllRoles(): RoleCollection;

	public function findRoleIdByName(string $name): ?int;

	public function getRoleNameById(int $roleId): ?string;

	public function roleExistsByName(string $name): bool;

	/**
	 * Permission rows of the given roles. Empty role list yields an empty collection.
	 *
	 * @param int[] $roleIds
	 */
	public function getPermissionsByRoleIds(array $roleIds): PermissionCollection;

	/**
	 * Permission rows of a single role.
	 */
	public function getPermissionsByRoleId(int $roleId): PermissionCollection;

	/**
	 * Reverse direction of {@see self::getPermissionsByRoleIds()}: the roles holding one of $permissionIds with a
	 * VALUE out of $values. The forward methods answer "what may this role do", and the question "who may act on
	 * this template" had no way to be asked - the mention hint of the notes asks exactly that.
	 *
	 * The values are the multivariables scope, so both template ids and the scope sentinels are accepted; the
	 * caller states which of them it means. Covered by the (VALUE, PERMISSION_ID) index of the table. An empty
	 * permission or value list yields an empty result and never "every role".
	 *
	 * @param array<int, int|string> $permissionIds
	 * @param int[] $values
	 * @return int[]
	 */
	public function getRoleIdsByPermissionValues(array $permissionIds, array $values): array;

	/**
	 * Access codes (role relations) grouped by role. Empty role list yields an empty map.
	 *
	 * @param int[] $roleIds
	 * @return array<int, string[]> role id => access codes
	 */
	public function getAccessCodesByRoleIds(array $roleIds): array;

	/**
	 * Access codes of several users at once, in the very shape the user model of the framework builds them for
	 * one user ({@see \Bitrix\Main\Access\User\UserModel::buildAccessCodes()}): the rows of the denormalized
	 * table of the platform plus the signature of every code, chat codes left out. The forward check reads them
	 * a user per query, so a caller holding a whole set of users pays a query per user for a set it could read
	 * in one.
	 *
	 * Every requested positive id is a key of the answer: a user with no row of their own gets an empty list,
	 * which is what the per-user read answers for them too. Non-positive ids and an empty input are dropped.
	 *
	 * The employee code and the reading of a converted company structure are NOT part of this: they belong to
	 * `getAccessCodes()` of the model, above the method this one mirrors.
	 *
	 * @param int[] $userIds
	 * @return array<int, string[]> user id => access codes
	 */
	public function getUserAccessCodes(array $userIds): array;

	public function addRole(string $name): AddResult;

	public function updateRoleName(int $roleId, string $name): UpdateResult;

	/**
	 * Deletes a role together with its permissions and relations.
	 */
	public function deleteRole(int $roleId): void;

	/**
	 * Raw multi-row insert of permission rows. The multivariables model stores several rows per
	 * (ROLE_ID, PERMISSION_ID) - one per template id - so rows are inserted as-is without deduplication.
	 * Callers delete the previous rows of the affected roles first (save flow) or seed a freshly created
	 * role (install). Rows with a non-positive role id or an empty permission id are skipped.
	 *
	 * @param array<int, array{ROLE_ID: int, PERMISSION_ID: int|string, VALUE: int}> $rows
	 */
	public function insertPermissions(array $rows): void;

	public function deletePermissionsByRole(int $roleId): void;

	/**
	 * @param array $filter ORM filter (e.g. in-area @VALUE and =PERMISSION_ID deletes of the delegate path)
	 */
	public function deletePermissions(array $filter): void;

	/**
	 * Replaces the relations of a role: drops the current relations and inserts the given access codes.
	 * The keys of the map are the access codes (the values mirror the framework relation format and are
	 * not persisted).
	 *
	 * @param array<string, mixed> $accessCodes access code => member type
	 */
	public function replaceRoleRelations(int $roleId, array $accessCodes): void;

	/**
	 * Drops permission rows whose VALUE scopes the deleted template id, narrowed to the given multivariables
	 * permission ids. Returns whether any such row existed (so the caller can invalidate its cache only then).
	 *
	 * @param string[] $permissionIds multivariables permission ids
	 */
	public function deleteOrphanPermissionsByTemplate(int $templateId, array $permissionIds): bool;

	/**
	 * Name of the permission table, used by the installer as a lock resource name.
	 */
	public function getPermissionTableName(): string;
}
