<?php

namespace Bitrix\BIConnector\Access\Component\PermissionSection;

use Bitrix\Main\Result;

/**
 * A section of the rights screen.
 *
 * A section knows its own code, its own right of editing, the rows it draws and the permission ids
 * it recognizes as its own. The last one is what makes an addressed save possible: a payload comes
 * from one section, and every id outside that section belongs to a save nobody asked for.
 */
interface PermissionSection
{
	public function getCode(): string;

	public function getTitle(): string;

	public function getHint(): string;

	/**
	 * Whether the current user may open and save this section.
	 */
	public function canEdit(): bool;

	/**
	 * Whether the section draws the button that adds a group row.
	 */
	public function canAddGroup(): bool;

	/**
	 * Rows of the section in the format the slider expects.
	 *
	 * @return array the `rights` payload of the ExternalAccessRightSection type of BX.UI.AccessRights.V2.App
	 */
	public function getRights(): array;

	/**
	 * Whether the permission id belongs to this section.
	 *
	 * Placeholders of a row that is not saved yet belong to their section too: a group nobody
	 * created is drawn by the section that created it.
	 */
	public function ownsPermissionId(int|string $permissionId): bool;

	/**
	 * Checks the section makes before its payload is applied.
	 *
	 * @param array $userGroups roles of the payload
	 */
	public function preSaveChecks(array $userGroups): Result;
}
