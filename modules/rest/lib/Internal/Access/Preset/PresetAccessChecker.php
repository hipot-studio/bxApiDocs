<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\Preset;

use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;
use Bitrix\Rest\Internal\Service\AccessPermissionService;
use Bitrix\Rest\Preset\Data\Element;
use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\SystemException;
use Bitrix\Rest\Internal\Access\UserContext;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main;

class PresetAccessChecker
{
	private AccessPermissionService $accessPermissionService;

	public function __construct(protected UserContext $userContext)
	{
		$this->accessPermissionService = new AccessPermissionService();
	}

	public function ensureCanEdit(array $presetData): void
	{
		$this->checkIntegrity($presetData);
		$this->checkAccess($presetData);
		$this->checkAccessToIncomingWebhook($presetData, PermissionType::Manage);
	}

	public function ensureCanEditOwn(array $presetData): void
	{
		$this->checkIntegrity($presetData);
		$this->checkAccess($presetData);
		$this->checkAccessToIncomingWebhook($presetData,  PermissionType::ManageOwn);
	}

	public function ensureCanCreateOwn(array $presetData)
	{
		$this->checkIntegrity($presetData);
		$this->checkAccess($presetData);
		$this->checkAccessToIncomingWebhook($presetData,  PermissionType::CreateOwn);
	}

	/**
	 * @param array $presetData
	 * @return void
	 * @throws SystemException
	 */
	protected function checkIntegrity(array $presetData): void
	{
		if (empty($presetData['OPTIONS']) || !is_array($presetData['OPTIONS']))
		{
			throw new SystemException(
				Loc::getMessage('REST_INTEGRATION_NOT_FOUND')
			);
		}

		if (!empty($presetData['REQUIRED_MODULES']) && is_array($presetData['REQUIRED_MODULES']))
		{
			foreach ($presetData['REQUIRED_MODULES'] as $val)
			{
				if (!Main\ModuleManager::isModuleInstalled($val))
				{
					throw new SystemException(
						Loc::getMessage(
							'REST_INTEGRATION_REQUIRED_MODULES',
							[
								'#MODULE_CODE#' => $val
							]
						)
					);
				}
			}
		}
	}

	/***
	 * @param array $presetData
	 * @return void
	 * @throws AccessDeniedException
	 */
	protected function checkAccess(array $presetData): void
	{
		if ($this->userContext->isAdmin())
		{
			return;
		}

		$isAdminOnly = ($presetData['ADMIN_ONLY'] ?? Element::VALUE_NO) === Element::VALUE_YES;
		$widgetNeeded = ($presetData['OPTIONS']['WIDGET_NEEDED'] ?? Element::VALUE_NO) !== Element::VALUE_DEFAULT;
		$applicationNeeded = ($presetData['OPTIONS']['APPLICATION_NEEDED'] ?? Element::VALUE_NO) !== Element::VALUE_DEFAULT;

		if ($isAdminOnly || $widgetNeeded || $applicationNeeded)
		{
			throw new AccessDeniedException(
				Loc::getMessage('REST_INTEGRATION_ACCESS_DENIED')
			);
		}
	}

	protected function checkAccessToIncomingWebhook(array $presetData, PermissionType $permissionType): void
	{
		if ($this->userContext->isAdmin())
		{
			return;
		}

		if (($presetData['QUERY_NEEDED'] ?? 'Y') === 'N')
		{
			return;
		}

		$accessCodes = $this->accessPermissionService->getAccessCodes(
			EntityType::IncomingWebhook,
			$permissionType
		);

		if ($this->userContext->canAccess($accessCodes))
		{
			return;
		}

		throw new AccessDeniedException(
			Loc::getMessage('REST_INTEGRATION_ACCESS_DENIED')
		);
	}
}
