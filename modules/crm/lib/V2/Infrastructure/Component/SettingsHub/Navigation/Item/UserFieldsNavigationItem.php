<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Component\SidePanelWrapper;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Rendering\SettingsHubRenderContext;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubWrappedNavigationItemLifecycle;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;

final class UserFieldsNavigationItem implements SettingsHubWrappedNavigationItemLifecycle
{
	/** @param list<string> $entityIds */
	public function __construct(
		private array $entityIds,
		private Uri $staticPageUrl,
	)
	{
	}

	public function id(): string
	{
		return 'user-fields';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		$configuration = $permissions->configuration();
		$scope = ConfigurationScope::ofCrm();

		return $configuration->canManageFields(ConfigurationCallSite::CrmConfigsComponent, $scope)
			|| $configuration->canDeleteFields(ConfigurationCallSite::CrmConfigsComponent, $scope);
	}

	public function canRead(UserPermissions $permissions): bool
	{
		$configuration = $permissions->configuration();
		$scope = ConfigurationScope::ofCrm();

		return $this->isVisible($permissions)
			&& (
				$configuration->canManageFields(ConfigurationCallSite::FieldsListComponent, $scope)
				|| $configuration->canDeleteFields(ConfigurationCallSite::FieldsListComponent, $scope)
			);
	}

	public function urlQueryParameters(): array
	{
		return [];
	}

	public function beforeRender(SettingsHubRenderContext $context): void
	{
		Loc::loadMessages(dirname(__DIR__, 7) . '/install/components/bitrix/crm.configs/templates/.default/template.php');
		$context->application->SetTitle(Loc::getMessage('CRM_CONFIGS_FIELDS'));
	}

	public function configureSidePanel(SidePanelWrapper $sidePanel): void
	{
	}

	public function component(HttpRequest $request): Contract\Component
	{
		$mode = $request->getQuery('mode');
		if (!in_array($mode, ['list', 'edit'], true))
		{
			$mode = 'entity_list';
		}

		$entityId = $request->getQuery('entity_id');
		if (!is_string($entityId) || !in_array($entityId, $this->entityIds, true))
		{
			$mode = 'entity_list';
		}

		$fieldId = 0;
		if ($mode === 'edit')
		{
			$requestedFieldId = $request->getQuery('field_id');
			if (is_string($requestedFieldId) && preg_match('/^UF_[0-9A-Z_]{1,47}$/D', $requestedFieldId) === 1)
			{
				$fieldId = $requestedFieldId;
			}
			elseif (!in_array($requestedFieldId, [null, '', 0, '0'], true))
			{
				$mode = 'entity_list';
			}
		}

		$url = clone $this->staticPageUrl;
		$url->addParams([
			'IFRAME' => 'Y',
			'IFRAME_TYPE' => 'SIDE_SLIDER',
		]);

		return new Component(
			'bitrix:crm.config.fields',
			[
				'SEF_MODE' => 'N',
				'ENABLE_CONTROL_PANEL' => false,
				'IS_SETTINGS_HUB' => true,
				'SETTINGS_HUB_URL' => $url->getUri(),
				'MODE' => $mode,
				'FIELS_ENTITY_ID' => $mode === 'entity_list' ? '' : $entityId,
				'FIELS_FIELD_ID' => $fieldId,
			],
			'.default',
		);
	}
}
