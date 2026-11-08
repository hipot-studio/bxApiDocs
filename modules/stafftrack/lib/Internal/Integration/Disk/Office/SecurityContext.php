<?php

namespace Bitrix\StaffTrack\Internal\Integration\Disk\Office;

use Bitrix\Intranet\Util;
use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Access\Model\OfficeModel;
use Bitrix\StaffTrack\Access\OfficeAccessController;
use Bitrix\StaffTrack\Access\OfficeAction;
use Bitrix\StaffTrack\Internal\Service\Office\OfficesAvailability;

/**
 * Access model of the office photo storage. Internal rights of the disk are switched off there, so
 * every answer is computed from the office domain: while the directory is switched off nothing is
 * allowed at all, and otherwise reading is open to an internal user of the portal while changing and
 * removing are open to whoever may run the matching office action. Managing operations of the disk -
 * rights, settings, renaming, moving, sharing, workflows - have no office action behind them and are
 * refused.
 *
 * A link to a file is a second way to the same directory and it outlives the office card, so the
 * barriers here repeat the ones the ajax surface of the directory stands on: the availability of the
 * directory ({@see \Bitrix\StaffTrack\Infrastructure\ActionFilters\OfficeUnavailableFilter}) and the
 * type of the caller ({@see \Bitrix\StaffTrack\Controller\Trait\IntranetUserTrait}) - the same question
 * to the intranet module and the same answer on a portal without it.
 *
 * The answers are sealed; what they are computed from is not. The three protected methods below are the
 * seams a test replaces: neither the disk nor the structure module is reachable from a unit test.
 */
class SecurityContext extends \Bitrix\Disk\Security\SecurityContext
{
	final public function canRead($objectId): bool
	{
		$userId = $this->resolveUserId();

		return $userId > 0 && $this->areOfficesAvailable() && $this->isIntranetUser($userId);
	}

	final public function canAdd($targetId): bool
	{
		return $this->checkOfficeAction(OfficeAction::ADD);
	}

	final public function canUpdate($objectId): bool
	{
		return $this->checkOfficeAction(OfficeAction::UPDATE);
	}

	final public function canDelete($objectId): bool
	{
		return $this->checkOfficeAction(OfficeAction::DELETE);
	}

	final public function canMarkDeleted($objectId): bool
	{
		return false;
	}

	final public function canRestore($objectId): bool
	{
		return false;
	}

	final public function canChangeRights($objectId): bool
	{
		return false;
	}

	final public function canChangeSettings($objectId): bool
	{
		return false;
	}

	final public function canCreateWorkflow($objectId): bool
	{
		return false;
	}

	final public function canStartBizProc($objectId): bool
	{
		return false;
	}

	final public function canMove($objectId, $targetId): bool
	{
		return false;
	}

	final public function canRename($objectId): bool
	{
		return false;
	}

	final public function canShare($objectId): bool
	{
		return false;
	}

	/**
	 * The storage is hidden: its files are reached through the office card and never through a listing
	 * of the disk.
	 */
	final public function getSqlExpressionForList($columnObjectId, $columnCreatedBy): string
	{
		return '1 = 0';
	}

	protected function areOfficesAvailable(): bool
	{
		return (new OfficesAvailability())->isAvailable();
	}

	protected function isIntranetUser(int $userId): bool
	{
		return !Loader::includeModule('intranet') || Util::isIntranetUser($userId);
	}

	/**
	 * The office an image belongs to is known to the photo table and not to the disk, and the rules of
	 * the directory are the same for every office, so the action is asked without one.
	 */
	protected function canRunOfficeAction(string $action, int $userId): bool
	{
		return OfficeAccessController::getInstance($userId)
			->check($action, OfficeModel::createFromId(0))
		;
	}

	private function checkOfficeAction(string $action): bool
	{
		$userId = $this->resolveUserId();

		return $userId > 0 && $this->areOfficesAvailable() && $this->canRunOfficeAction($action, $userId);
	}

	private function resolveUserId(): int
	{
		$userId = $this->getUserId();

		return $userId === self::GUEST_USER ? 0 : (int)$userId;
	}
}
