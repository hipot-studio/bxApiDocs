<?php

namespace Bitrix\Intranet\User\Grid\Settings;

use Bitrix\Intranet\Component\UserList;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

final class UserSettings extends \Bitrix\Main\Grid\Settings
{
	private array $userFields;
	private string $extensionName;
	private string $extensionLoadName;
	private array $adminIdList = [];
	private array $viewFields;
	private ?array $filterFields = null;

	public function __construct(array $params)
	{
		parent::__construct($params);

		global $USER_FIELD_MANAGER;

		$this->userFields = $USER_FIELD_MANAGER->getUserFields(\Bitrix\Main\UserTable::getUfId(), 0, LANGUAGE_ID, false);
		$this->initViewFields();

		$this->extensionName = $params['extensionName'] ?? 'Intranet.Grid.UserGrid';
		$this->extensionLoadName = $params['extensionLoadName'] ?? 'intranet.grid.user-grid';
	}

	public function getUserFields(): array
	{
		return $this->userFields;
	}

	public function getExtensionName(): string
	{
		return $this->extensionName;
	}

	public function getExtensionLoadName(): string
	{
		return $this->extensionLoadName;
	}

	public function isUserAdmin($userId): bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			return \CBitrix24::IsPortalAdmin($userId);
		}

		return in_array($userId, $this->getAdminIdList());
	}

	public function getViewFields(): array
	{
		return $this->viewFields;
	}

	public function getFilterFields(): ?array
	{
		return $this->filterFields;
	}

	public function setFilterFields(array $filterFields): void
	{
		$this->filterFields = $filterFields;
	}

	private function initViewFields(): void
	{
		$result = [];
		$val = Option::get('intranet', 'user_list_user_property_available', false, SITE_ID);

		if (!empty($val))
		{
			$val = unserialize($val, ["allowed_classes" => false]);
			if (
				is_array($val)
				&& !empty($val)
			)
			{
				$result = $val;
			}
		}

		$this->viewFields = !empty($result) ? $result : UserList::getUserPropertyListDefault();
	}

	private function getAdminIdList(): array
	{
		if (empty($this->adminIdList))
		{
			$dbAdminList = \CAllGroup::GetGroupUserEx(1);

			while($admin = $dbAdminList->fetch())
			{
				$this->adminIdList[] = (int)$admin['USER_ID'];
			}
		}

		return $this->adminIdList;
	}
}