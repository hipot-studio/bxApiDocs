<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Feature;
use Bitrix\Mobile\Context;
use Bitrix\Mobile\Public\Features\ManagedTabPresetsFeature;
use Bitrix\Mobile\Tab\Manager;
use Bitrix\Mobile\Tab\Preset\Dto\PresetReference;
use Bitrix\Mobile\Tab\Preset\Service\CompatibleTabPresetStateWriter;
use Bitrix\Mobile\Tab\Preset\Service\ManagedPresetService;
use Bitrix\Mobile\Tab\Preset\Service\PresetAssignmentV2Service;
use Bitrix\Mobile\Tab\Preset\Service\PortalDefaultPresetService;
use Bitrix\Mobile\Tab\Preset\Service\TabConfigValidator;
use Bitrix\Mobile\Tab\Preset\Service\TabPresetConfigProvider;
use Bitrix\Mobile\Tab\Preset\Service\TabPresetDataProvider;

Loc::loadMessages(__FILE__);

class ManagedTabPresets extends \Bitrix\Main\Engine\Controller
{
	public function getDataAction(): ?array
	{
		return $this->executeSafely(function (): ?array {
			if (!$this->ensureManagedFeatureEnabled())
			{
				return null;
			}

			$context = new Context();

			return (new TabPresetDataProvider(
				Manager::createManaged($context),
				$context,
			))->getData();
		});
	}

	public function savePresetAction(?int $id = null, string $title = '', array $tabs = []): ?array
	{
		return $this->executeSafely(function () use ($id, $title, $tabs): ?array {
			if (!$this->ensureManagedFeatureEnabled())
			{
				return null;
			}

			$context = new Context();
			$manager = Manager::createManaged($context);
			$row = (new ManagedPresetService($manager))->save((int)$context->userId, $title, $tabs, $id);

			return [
				'preset' => (new TabPresetDataProvider($manager, $context))
					->formatManagedPreset($row)
				,
			];
		});
	}

	public function deletePresetAction(int $id): ?array
	{
		return $this->executeSafely(function () use ($id): ?array {
			if (!$this->ensureManagedFeatureEnabled())
			{
				return null;
			}

			$context = new Context();
			(new ManagedPresetService(Manager::createManaged($context)))->delete(
				(int)$context->userId,
				$id,
				(string)$context->siteId,
			);

			return ['success' => true];
		});
	}

	public function assignPresetAction(string $presetId, array $recipients = [], array $tabs = []): ?array
	{
		return $this->executeSafely(function () use ($presetId, $recipients, $tabs): ?array {
			if (!$this->ensureManagedFeatureEnabled())
			{
				return null;
			}

			$context = new Context();
			$manager = Manager::createManaged($context);
			$version = (new PresetAssignmentV2Service($manager))->assign(
				(int)$context->userId,
				PresetReference::fromPresetId($presetId),
				$recipients,
				empty($tabs) ? null : $tabs,
			);

			return [
				'success' => true,
				'version' => $version,
			];
		});
	}

	public function setCurrentPresetAction(string $presetId, array $tabs = []): ?array
	{
		return $this->executeSafely(function () use ($presetId, $tabs): ?array {
			if (!$this->ensureManagedFeatureEnabled())
			{
				return null;
			}

			$context = new Context();
			$manager = Manager::createManaged($context);
			$stateWriter = new CompatibleTabPresetStateWriter(
				$manager,
				(int)$context->userId,
				(string)$context->siteId,
			);
			if (str_starts_with($presetId, 'managed:'))
			{
				$currentTabs = $stateWriter->setPreset($presetId);
				if ($currentTabs === null)
				{
					$this->addError(new Error(Loc::getMessage('MOBILE_TABS_MANAGED_PRESET_NOT_FOUND'), 404));

					return null;
				}

				return [
					'tabs' => $currentTabs,
					'current' => $presetId,
				];
			}

			$configProvider = new TabPresetConfigProvider($manager);
			$currentTabs = !empty($tabs)
				? (new TabConfigValidator($manager))->normalize($tabs)
				: $configProvider->getPresetConfigByName($presetId);
			if ($currentTabs === null)
			{
				$this->addError(new Error(Loc::getMessage('MOBILE_TABS_MANAGED_PRESET_NOT_FOUND'), 404));

				return null;
			}

			$currentTabs = !empty($tabs)
				? $stateWriter->setBuiltinSnapshot($presetId, $currentTabs)
				: $stateWriter->setPreset($presetId);
			if ($currentTabs === null)
			{
				$this->addError(new Error(Loc::getMessage('MOBILE_TABS_MANAGED_PRESET_NOT_FOUND'), 404));

				return null;
			}

			return [
				'tabs' => $currentTabs,
				'current' => $presetId,
			];
		});
	}

	public function setPortalDefaultPresetAction(string $presetId): ?array
	{
		return $this->executeSafely(function () use ($presetId): ?array {
			if (!$this->ensureManagedFeatureEnabled())
			{
				return null;
			}

			$context = new Context();
			$manager = Manager::createManaged($context);
			(new PortalDefaultPresetService($manager))->setDefault(
				(string)$context->siteId,
				(int)$context->userId,
				$presetId,
			);

			return ['success' => true];
		});
	}

	private function ensureManagedFeatureEnabled(): bool
	{
		if (!Feature::isEnabled(ManagedTabPresetsFeature::class))
		{
			$this->addError(new Error(Loc::getMessage('MOBILE_TABS_MANAGED_PRESET_FEATURE_DISABLED'), 403));

			return false;
		}

		return true;
	}

	private function executeSafely(callable $action): ?array
	{
		try
		{
			return $action();
		}
		catch (\Throwable $exception)
		{
			$this->addDomainError($exception);

			return null;
		}
	}

	private function addDomainError(\Throwable $exception): void
	{
		if ($exception->getMessage() === PresetAssignmentV2Service::ERROR_MESSAGE_RECIPIENT_NOT_ALLOWED)
		{
			$this->addError(new Error(
				PresetAssignmentV2Service::ERROR_RECIPIENT_NOT_ALLOWED,
				PresetAssignmentV2Service::ERROR_RECIPIENT_NOT_ALLOWED,
			));

			return;
		}

		if ($exception->getMessage() === ManagedPresetService::ERROR_MESSAGE_OWNED_PRESET_LIMIT_EXCEEDED)
		{
			$this->addError(new Error(
				Loc::getMessage('MOBILE_TABS_MANAGED_PRESET_LIMIT_EXCEEDED'),
				ManagedPresetService::ERROR_OWNED_PRESET_LIMIT_EXCEEDED,
			));

			return;
		}

		if ($exception->getMessage() === PresetAssignmentV2Service::ERROR_MESSAGE_HR_UNAVAILABLE)
		{
			$this->addError(new Error(
				Loc::getMessage('MOBILE_TABS_MANAGED_PRESET_HR_UNAVAILABLE'),
				422,
			));

			return;
		}

		$code = (int)$exception->getCode();
		$errorCode = in_array($code, [403, 404], true) ? $code : 422;
		$message = match ($errorCode)
		{
			403 => Loc::getMessage('MOBILE_TABS_MANAGED_PRESET_ACCESS_DENIED'),
			404 => Loc::getMessage('MOBILE_TABS_MANAGED_PRESET_NOT_FOUND'),
			default => Loc::getMessage('MOBILE_TABS_MANAGED_PRESET_ACTION_FAILED'),
		};

		$this->addError(new Error($message, $errorCode));
	}
}
