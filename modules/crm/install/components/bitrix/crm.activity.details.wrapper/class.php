<?php

declare(strict_types=1);

use Bitrix\Crm\Security\PermissionToken;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CrmActivityDetailsWrapper extends \CBitrixComponent
{
	public function executeComponent(): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		$activityId = (int)($this->arParams['activityId'] ?? 0);
		if ($activityId <= 0)
		{
			$this->showError();

			return;
		}

		$userId = (int)CurrentUser::get()->getId();

		$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
		if (!$activity)
		{
			$this->showError();

			return;
		}

		$provider = \CCrmActivity::GetActivityProvider($activity);
		if (!$provider)
		{
			$this->showError();

			return;
		}

		$isReadOnly = false;

		if (!$provider::checkReadPermission($activity, $userId))
		{
			$token = $this->request->get('act');
			if (!is_string($token) || !PermissionToken::canViewActivity($token, $activityId, $userId))
			{
				$this->showError();

				return;
			}

			$isReadOnly = true;
		}
		elseif (!$provider::checkUpdatePermission($activity, $userId))
		{
			$isReadOnly = true;
		}

		$this->arResult['ACTIVITY_ID'] = $activityId;
		$this->arResult['IS_READ_ONLY'] = $isReadOnly;

		$this->includeComponentTemplate();
	}

	private function showError(): void
	{
		$this->includeComponentTemplate('error');
	}
}
