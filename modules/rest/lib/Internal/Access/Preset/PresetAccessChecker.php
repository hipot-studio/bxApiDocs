<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\Preset;

use Bitrix\Rest\Internal\Access\AppAccessChecker;
use Bitrix\Rest\Preset\Data\Element;
use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\SystemException;
use Bitrix\Rest\Internal\Access\WebhookAccessChecker;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main;

class PresetAccessChecker
{
	private WebhookAccessChecker $webhookAccessChecker;
	private AppAccessChecker $appAccessChecker;

	public function __construct(protected RestUserModel $user)
	{
		$this->webhookAccessChecker = new WebhookAccessChecker($user->getId());
		$this->appAccessChecker = new AppAccessChecker($user->getId());
	}

	public function ensureCanEdit(array $presetData): void
	{
		$this->checkIntegrity($presetData);
		$this->checkAccess($presetData, allowNonAdminNonWebhookPreset: false);
		$this->checkAccessToIncomingWebhook($presetData, $this->webhookAccessChecker->canManageAnyIncomingWebhook(...));
		$this->checkAccessToApplication($presetData, $this->appAccessChecker->canInstallLocal(...));
	}

	public function ensureCanEditOwn(array $presetData): void
	{
		$this->checkIntegrity($presetData);
		$this->checkAccess($presetData, allowNonAdminNonWebhookPreset: false);
		$this->checkAccessToIncomingWebhook($presetData, $this->webhookAccessChecker->canManageOwnIncomingWebhook(...));
		$this->checkAccessToApplication($presetData, $this->getAppAccessCheckerCallback($presetData));
	}

	public function ensureCanCreateOwn(array $presetData): void
	{
		$this->checkIntegrity($presetData);
		$this->checkAccess($presetData, allowNonAdminNonWebhookPreset: true);
		$this->checkAccessToIncomingWebhook($presetData, $this->webhookAccessChecker->canCreateOwnIncomingWebhook(...));
		$this->checkAccessToApplication($presetData, $this->getAppAccessCheckerCallback($presetData));
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
	protected function checkAccess(array $presetData, bool $allowNonAdminNonWebhookPreset): void
	{
		if ($this->user->isAdmin())
		{
			return;
		}

		if (($presetData['ADMIN_ONLY'] ?? Element::VALUE_NO) === Element::VALUE_YES)
		{
			throw new AccessDeniedException(
				Loc::getMessage('REST_INTEGRATION_ACCESS_DENIED')
			);
		}

		if (!$allowNonAdminNonWebhookPreset && !$this->isWebhookPreset($presetData))
		{
			throw new AccessDeniedException(
				Loc::getMessage('REST_INTEGRATION_ACCESS_DENIED')
			);
		}
	}

	/**
	 * @param callable(): bool $accessCheck
	 * @throws AccessDeniedException
	 */
	protected function checkAccessToIncomingWebhook(array $presetData, callable $accessCheck): void
	{
		if (!$this->isWebhookPreset($presetData))
		{
			return;
		}

		if (!$accessCheck())
		{
			throw new AccessDeniedException(
				Loc::getMessage('REST_INTEGRATION_ACCESS_DENIED')
			);
		}
	}

	protected function checkAccessToApplication(array $presetData, callable $accessCheck): void
	{
		if (!$this->isApplicationPreset($presetData))
		{
			return;
		}
		if (!$accessCheck())
		{
			throw new AccessDeniedException(
				Loc::getMessage('REST_INTEGRATION_ACCESS_DENIED')
			);
		}
	}

	private function isApplicationPreset(array $presetData): bool
	{
		return $this->getPresetValue($presetData, 'APPLICATION_NEEDED', Element::VALUE_DEFAULT) !== Element::VALUE_DEFAULT
			|| $this->getPresetValue($presetData, 'WIDGET_NEEDED', Element::VALUE_DEFAULT) !== Element::VALUE_DEFAULT;
	}

	private function isWebhookPreset(array $presetData): bool
	{
		return $this->getPresetValue($presetData, 'QUERY_NEEDED', Element::VALUE_YES) !== Element::VALUE_DEFAULT;
	}

	private function getPresetValue(array $presetData, string $key, string $default): string
	{
		if (array_key_exists($key, $presetData))
		{
			return (string)$presetData[$key];
		}

		$options = $presetData['OPTIONS'] ?? null;
		if (is_array($options) && array_key_exists($key, $options))
		{
			return (string)$options[$key];
		}

		return $default;
	}

	private function isPersonalApplicationPreset(array $presetData): bool
	{
		return $this->getPresetValue($presetData, 'IS_APPLICATION_PERSONAL', Element::VALUE_DEFAULT) === Element::VALUE_YES;
	}

	private function getAppAccessCheckerCallback(array $presetData): callable
	{
		return ($this->isApplicationPreset($presetData) && !$this->isPersonalApplicationPreset($presetData))
			? $this->appAccessChecker->canInstallLocal(...)
			: $this->appAccessChecker->canInstallPersonal(...)
		;
	}
}
