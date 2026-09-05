<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use \Bitrix\Landing\Manager;
use \Bitrix\Landing\Rights;
use \Bitrix\Landing\Role;
use \Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

\CBitrixComponent::includeComponentClass('bitrix:landing.base');

class LandingRolesComponent extends LandingBaseComponent
{
	/**
	 * Action for switch mode.
	 * @return bool
	 */
	protected function actionMode()
	{
		if (Rights::isAdmin() && Rights::canSwitchMode())
		{
			Rights::switchMode();
			return true;
		}
		$this->addError(
			'ACCESS_DENIED'
		);
		return false;
	}

	/**
	 * Save action (extended mode).
	 * @return bool
	 */
	protected function actionSaveExtended()
	{
		if ($this->canChangeRoles())
		{
			$rights = (array)$this->request('rights');
			// the whole set is checked before the first write: the form is filled before the action
			// runs, so a partly written set would be shown by the values it had before the save
			foreach ($rights as $code => $access)
			{
				if (!is_string($code) || !Rights::isExtendedGrantAcceptable($code))
				{
					// the codes themselves are not named: the message must not describe the rights of
					// another scope. false keeps the dispatcher from redirecting past the error
					$this->addError(
						'LANDING_ERROR_RIGHTS_SCOPE_MISMATCH'
					);
					return false;
				}
			}
			foreach ($rights as $code => $access)
			{
				Rights::setAdditionalRightExtended(
					$code,
					(array)$access
				);
			}

			return true;
		}

		$this->addError(
			'ACCESS_DENIED'
		);
		return false;
	}

	/**
	 * Save action (role mode).
	 * @return bool
	 */
	protected function actionSave()
	{
		if ($this->canChangeRoles())
		{
			$rights = $this->request('rights');
			$roles = (array)$this->request('roles');
			$rolesFull = $this->arResult['ROLES'];
			// first delete roles if need
			foreach ($rolesFull as $roleId => $roleItem)
			{
				if (!in_array($roleId, $roles))
				{
					Role::delete($roleId);
				}
			}
			// set access for roles
			if (
				isset($rights['ROLE_ID']) &&
				isset($rights['ACCESS_CODE']) &&
				is_array($rights['ACCESS_CODE']) &&
				is_array($rights['ROLE_ID'])
			)
			{
				$roles = [];
				foreach ($rights['ROLE_ID'] as $i => $roleId)
				{
					if (isset($rights['ACCESS_CODE'][$i]))
					{
						if (!isset($roles[$roleId]))
						{
							$roles[$roleId] = [];
						}
						$roles[$roleId][] = $rights['ACCESS_CODE'][$i];
					}
				}
				foreach ($roles as $id => $codes)
				{
					unset($rolesFull[$id]);
					Role::setAccessCodes($id, $codes);
				}
				// set empty for other roles
				foreach ($rolesFull as $roleId => $v)
				{
					Role::setAccessCodes($roleId);
				}
			}
			else
			{
				foreach ($rolesFull as $roleId => $v)
				{
					Role::setAccessCodes($roleId);
				}
			}
			return true;
		}

		$this->addError(
			'ACCESS_DENIED'
		);
		return false;
	}

	/**
	 * May the current user change the roles?
	 * Role::setRights() and Role::setAccessCodes() refuse to write without the permissions feature,
	 * but Role::delete() has no gate of its own, so the saving actions check the feature themselves,
	 * the same way PublicAction\Role::init() does.
	 * @return bool
	 */
	protected function canChangeRoles(): bool
	{
		return Rights::isAdmin() && Manager::checkFeature(Manager::FEATURE_PERMISSIONS_AVAILABLE);
	}

	/**
	 * Get all access codes from roles.
	 * @return array
	 */
	protected function getAccessCodes()
	{
		$accessCodes = [];

		foreach (Role::fetchAll() as $role)
		{
			foreach ($role['ACCESS_CODES'] as $codeRow)
			{
				$code = $codeRow['CODE'];
				$accessCodes[$code] = $codeRow;
				$accessCodes[$code]['ROLE_ID'] = $role['ID'];
			}
		}

		return $accessCodes;
	}

	/**
	 * Base executable method.
	 * @return void
	 */
	public function executeComponent()
	{
		$init = $this->init();

		// access only for admin
		if ($init && !Rights::isAdmin())
		{
			$init = false;
			$this->addError(
				'ACCESS_DENIED',
				'',
				true
			);
		}

		if ($init)
		{
			$this->checkParam('TYPE', '');
			$this->checkParam('PAGE_URL_ROLE_EDIT', '');

			\Bitrix\Landing\Site\Type::setScope(
				$this->arParams['TYPE']
			);

			$this->arResult['EXTENDED'] = Rights::isExtendedMode();

			if ($this->arResult['EXTENDED'])
			{
				$this->arResult['ACCESS_CODES'] = [];
				$this->arResult['ADDITIONAL'] = Rights::getAdditionalRightsLabels();
				foreach ($this->arResult['ADDITIONAL'] as $code => $title)
				{
					$this->arResult['ACCESS_CODES'][$code] = Rights::getAdditionalRightExtended(
						$code
					);
				}
			}
			else
			{
				$this->arResult['ROLES'] = Role::fetchAll();
				$this->arResult['ACCESS_CODES'] = $this->getAccessCodes();
			}
		}

		parent::executeComponent();
	}
}