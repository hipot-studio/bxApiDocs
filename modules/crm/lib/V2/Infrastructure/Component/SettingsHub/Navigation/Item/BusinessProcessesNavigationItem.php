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

final class BusinessProcessesNavigationItem implements SettingsHubWrappedNavigationItemLifecycle
{
	/** @param \Closure(string): bool $isEntityAllowed */
	public function __construct(
		private bool $isBizprocAvailable,
		private bool $isBizprocEnabled,
		private \Closure $isEntityAllowed,
		private Uri $staticPageUrl,
	)
	{
	}

	public function id(): string
	{
		return 'business-processes';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->configuration()->canManageBizprocTemplates(
			ConfigurationCallSite::CrmConfigsComponent,
			ConfigurationScope::ofCrm(),
		);
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $this->isVisible($permissions)
			&& $this->isBizprocAvailable
			&& $this->isBizprocEnabled
			&& $permissions->configuration()->canManageBizprocTemplates(
				ConfigurationCallSite::BizprocTemplateComponent,
				ConfigurationScope::ofCrm(),
			);
	}

	public function urlQueryParameters(): array
	{
		return [];
	}

	public function beforeRender(SettingsHubRenderContext $context): void
	{
		Loc::loadMessages(dirname(__DIR__, 7) . '/install/components/bitrix/crm.configs/templates/.default/template.php');
		$context->application->SetTitle(Loc::getMessage('CRM_CONFIGS_BP'));
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
		$isEntityAllowed = $mode !== 'entity_list'
			&& is_string($entityId)
			&& (($mode === 'edit' && $entityId === 'CRM_ORDER') || ($this->isEntityAllowed)($entityId));
		$entityId = $isEntityAllowed ? $entityId : '';

		$bpId = $request->getQuery('bp_id');
		$bpId = $mode === 'edit'
			&& (is_string($bpId) || is_int($bpId))
			&& preg_match('/^[0-9]+$/D', (string)$bpId) === 1
			? (filter_var($bpId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0)
			: 0;

		$url = clone $this->staticPageUrl;
		$url->addParams([
			'IFRAME' => 'Y',
			'IFRAME_TYPE' => 'SIDE_SLIDER',
		]);

		return new Component(
			'bitrix:crm.config.bp',
			[
				'SEF_MODE' => 'N',
				'ENABLE_CONTROL_PANEL' => false,
				'IS_SETTINGS_HUB' => true,
				'SETTINGS_HUB_URL' => $url->getUri(),
				'MODE' => $mode,
				'BP_ENTITY_ID' => $entityId,
				'BP_BP_ID' => $bpId,
			],
			'.default',
		);
	}
}
