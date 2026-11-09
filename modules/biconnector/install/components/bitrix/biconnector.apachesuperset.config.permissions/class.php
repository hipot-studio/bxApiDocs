<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Component\PermissionConfig;
use Bitrix\BIConnector\Access\Component\PermissionSection\SectionSet;
use Bitrix\BIConnector\Access\Component\SectionPayload;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicenseLock;
use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\UI\Toolbar\Facade\Toolbar;

Main\Loader::requireModule('biconnector');

class ApacheSupersetConfigPermissionsComponent
	extends CBitrixComponent
	implements Main\Errorable
{
	use Main\ErrorableImplementation;

	/**
	 * Sections the person may edit; resolved by the access check.
	 */
	private ?SectionSet $sections = null;

	/**
	 * @inheritDoc
	 */
	public function executeComponent()
	{
		global $APPLICATION;

		/**
		 * @var \CMain $APPLICATION
		 */

		$this->errorCollection = new Main\ErrorCollection();

		$analyticEvent = new AnalyticsEvent('open_editor', 'BI_Builder', 'roles');
		$analyticEvent->setSection('BI_Builder')->setElement('menu');

		if (!$this->checkAccessPermissions())
		{
			$analyticEvent->setStatus('blocked')->send();
			$this->errorCollection->setError(new Error(Loc::getMessage('BICONNECTOR_APACHESUPERSET_CONFIG_PERMISSIONS_WRONG_PERMISSION')));
			$this->printErrors();

			return;
		}

		if (SelfHostedLicenseLock::isDashboardLocked())
		{
			$analyticEvent->setStatus('blocked')->send();
			$this->errorCollection->setError(new Error(SelfHostedLicenseLock::getRestrictionMessage()));
			$this->printErrors();

			return;
		}

		$isSetTitle = ($this->arParams['SET_TITLE'] ?? 'Y') === 'Y';
		if ($isSetTitle)
		{
			$APPLICATION->SetTitle(
				Loc::getMessage('BICONNECTOR_APACHESUPERSET_CONFIG_PERMISSIONS_ROLE_EDIT_COMP_ACCESS_RIGHTS')
			);
		}

		$analyticEvent->setStatus('success')->send();

		$this->initResult();
		$this->includeComponentTemplate();
	}

	/**
	 * @return void
	 */
	private function initResult(): void
	{
		$sections = $this->sections;
		if ($sections === null || $sections->isEmpty())
		{
			return;
		}

		$this->arResult['ERRORS'] = [];
		$this->arResult['ACTION_URI'] = $this->getPath() . '/ajax.php';
		$this->arResult['NAME'] = Loc::getMessage('BICONNECTOR_APACHESUPERSET_CONFIG_PERMISSIONS_ROLE_EDIT_COMP_TEMPLATE_NAME');

		$configPermissions = new PermissionConfig($sections);

		$this->arResult['USER_GROUPS'] = $configPermissions->getUserGroups();
		$this->arResult['ACCESS_RIGHTS'] = $configPermissions->getAccessRights();

		$this->initLeftMenu($sections);
		$this->initSectionData($sections);
	}

	/**
	 * Table of contents of the screen: the sections of the matrix, in its order.
	 *
	 * A person with a single section gets no column: there is nothing to scroll to, and the screen
	 * of one section is the screen as it was before the sections appeared.
	 */
	private function initLeftMenu(SectionSet $sections): void
	{
		$items = [];
		foreach ($sections->getSections() as $section)
		{
			$items[] = [
				'CODE' => $section->getCode(),
				'NAME' => $section->getTitle(),
			];
		}

		$this->arResult['LEFT_MENU'] = $items;
		$this->arResult['SHOW_LEFT_MENU'] = count($items) > 1;
	}

	/**
	 * Data of the groups the sections of this person draw, and of no other ones.
	 *
	 * The popup of a group and the list of its dashboards or tables are the payload of one axis,
	 * and a person without that axis on his screen has no business reading its catalog.
	 */
	private function initSectionData(SectionSet $sections): void
	{
		$payload = (new SectionPayload($sections))->getData();

		$this->arResult['NEW_GROUP_PERMISSIONS'] = $payload['NEW_GROUP_PERMISSIONS'];
		$this->arResult['DASHBOARD_GROUPS'] = $payload['DASHBOARD_GROUPS'];
		$this->arResult['DASHBOARDS'] = $payload['DASHBOARDS'];
		$this->arResult['NEW_TABLE_GROUP_PERMISSIONS'] = $payload['NEW_TABLE_GROUP_PERMISSIONS'];
		$this->arResult['TABLE_GROUPS'] = $payload['TABLE_GROUPS'];
		$this->arResult['TABLES'] = $payload['TABLES'];
		$this->arResult['USER_DATA'] = [
			'id' => AccessController::getCurrent()->getUser()->getUserId(),
			'isAdmin' => AccessController::getCurrent()->getUser()->isAdmin(),
			// The warning of the group popup asks about the rights of the dashboard axis and nothing
			// wider, the same question lib/controller/group.php answers.
			'hasAccessToPermission' => $sections->hasSection(PermissionConfig::SECTION_DASHBOARD_GROUP_RIGHTS),
			'accessibleGroupIds' => $payload['ACCESSIBLE_GROUP_IDS'],
		];
	}

	/**
	 * Check can user view and change rights.
	 *
	 * @return bool
	 */
	private function checkAccessPermissions(): bool
	{
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			return false;
		}

		// The screen is opened by the right of any of its sections and no longer by the right of the
		// module rights alone: the right of the table groups and the right of the dashboard groups
		// are rights of their own, and the person holding one of them has a section to work with.
		$this->sections = SectionSet::forCurrentUser();

		return !$this->sections->isEmpty();
	}

	/**
	 * Show component errors.
	 *
	 * @return void
	 */
	private function printErrors(): void
	{
		Toolbar::deleteFavoriteStar();
		foreach ($this->errorCollection as $error)
		{
			$this->includeErrorComponent($error->getMessage());
		}
	}

	/**
	 * Include errors component.
	 *
	 * @param string $errorMessage
	 * @param string|null $description
	 *
	 * @return void
	 */
	protected function includeErrorComponent(string $errorMessage, ?string $description = null): void
	{
		global $APPLICATION;

		$APPLICATION->IncludeComponent(
			'bitrix:ui.info.error',
			'',
			[
				'TITLE' => $errorMessage,
				'DESCRIPTION' => $description,
			]
		);
	}
}
