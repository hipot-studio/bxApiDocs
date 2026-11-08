<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Service\TableGroupService;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Superset\ActionFilter\BIConstructorAccess;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/**
 * Group management of the tables section.
 *
 * The controller writes nothing itself: every change goes through TableGroupService, which owns the
 * transaction and the reset of the caches. What belongs here is the single check of the right to
 * arrange the groups and the translation of the request of the popup into the calls of the service.
 */
class TableGroup extends Controller
{
	private const NEW_GROUP_ID_PREFIX = 'new_';

	protected function getDefaultPreFilters(): array
	{
		$additionalFilters = [
			new BIConstructorAccess(),
		];

		if (Loader::includeModule('intranet'))
		{
			$additionalFilters[] = new IntranetUser();
		}

		return [
			...parent::getDefaultPreFilters(),
			// Every action of the groups changes something or names the whole catalog: the section
			// asks for them by POST, and the address bar has no business carrying such a request.
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			...$additionalFilters,
		];
	}

	/**
	 * Creates a group or renames an existing one and replaces its composition.
	 *
	 * @param array{id: string, name: string} $group Group of the popup; a group the client has just
	 *                                               drawn carries an id starting with "new_".
	 * @param string[] $tableCodes The whole composition of the group after the save.
	 * @return array{id: int}|null The identifier of the saved group; the popup goes on changing it.
	 */
	public function saveAction(array $group, array $tableCodes = []): ?array
	{
		if (!$this->checkTableRightsAccess())
		{
			return null;
		}

		$name = trim((string)($group['name'] ?? ''));
		$groupId = $this->resolveGroupId((string)($group['id'] ?? ''));
		$isNewGroup = $groupId === null;

		$saveResult = TableGroupService::saveGroup($groupId, $name, $tableCodes);
		if (!$saveResult->isSuccess())
		{
			$this->addErrors($saveResult->getErrors());

			return null;
		}

		$groupId = (int)$saveResult->getData()['id'];

		if ($isNewGroup)
		{
			// A group nobody can see is a group nobody can fill: the one who created it gets it at once.
			// The grant follows the one of the dashboard groups (Group::saveAction): the roles of the creator
			// that manage the rights get it, and an administrator without such a role grants nobody.
			AccessController::getCurrent()->getUser()->fillDefaultTableGroupAccess($groupId);
		}

		return ['id' => $groupId];
	}

	public function deleteAction(int $groupId): ?bool
	{
		if (!$this->checkTableRightsAccess())
		{
			return null;
		}

		$group = TableGroupTable::getById($groupId)->fetchObject();
		if ($group === null)
		{
			$this->addError(new UserFacingError(
				Loc::getMessage('BICONNECTOR_CONTROLLER_TABLE_GROUP_ERROR_NOT_FOUND') ?? '',
			));

			return null;
		}

		if ($group->getType() === TableGroupTable::GROUP_TYPE_SYSTEM)
		{
			$this->addError(new UserFacingError(
				Loc::getMessage('BICONNECTOR_CONTROLLER_TABLE_GROUP_ERROR_DELETE_SYSTEM') ?? '',
			));

			return null;
		}

		$deleteResult = TableGroupService::deleteCustomGroup($groupId);
		if (!$deleteResult->isSuccess())
		{
			$this->addErrors($deleteResult->getErrors());

			return null;
		}

		return true;
	}

	/**
	 * The groups and the catalog the popup of the section is drawn from.
	 */
	public function loadSettingsDataAction(): ?array
	{
		if (!$this->checkTableRightsAccess())
		{
			return null;
		}

		return [
			'groups' => TableGroupService::getGroups(),
			'tables' => TableGroupService::getTables(),
		];
	}

	private function checkTableRightsAccess(): bool
	{
		// The tables live in the analytics workspace: a tariff without it has no section to arrange.
		if (!Feature::isExternalEntitiesEnabled())
		{
			$this->addError(new UserFacingError(
				Loc::getMessage('BICONNECTOR_CONTROLLER_TABLE_GROUP_ERROR_WORKSPACE_TARIFF') ?? '',
			));

			return false;
		}

		// Without the axis the groups are drawn nowhere, so there is nothing to arrange either.
		if (
			Feature::isTablePermissionsEnabled()
			&& AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_SETTINGS_EDIT_TABLE_RIGHTS)
		)
		{
			return true;
		}

		$this->addError(new UserFacingError(
			Loc::getMessage('BICONNECTOR_CONTROLLER_TABLE_GROUP_ACCESS_ERROR_MODIFY') ?? '',
		));

		return false;
	}

	/**
	 * @return int|null Null means the group is being created right now.
	 */
	private function resolveGroupId(string $groupIdCode): ?int
	{
		if ($groupIdCode === '' || str_starts_with($groupIdCode, self::NEW_GROUP_ID_PREFIX))
		{
			return null;
		}

		$groupId = PermissionDictionary::getTableGroupIdFromPermission($groupIdCode);

		return $groupId > 0 ? $groupId : null;
	}
}
