<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\EntityPreset;
use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubWrappedNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Web\Uri;

final class RequisiteTemplatesNavigationItem implements SettingsHubWrappedNavigationItem
{
	public function __construct(
		private Uri $staticPageUrl,
	)
	{
	}

	public function id(): string
	{
		return 'requisite-templates';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin();
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin();
	}

	public function urlQueryParameters(): array
	{
		return [];
	}

	public function component(HttpRequest $request): Contract\Component
	{
		$mode = $request->getQuery('mode');
		if (!in_array($mode, ['list', 'edit', 'ufields'], true))
		{
			$mode = 'list';
		}

		$url = clone $this->staticPageUrl;
		$url->addParams([
			'IFRAME' => 'Y',
			'IFRAME_TYPE' => 'SIDE_SLIDER',
		]);

		$parameters = [
			'SEF_MODE' => 'N',
			'ENABLE_CONTROL_PANEL' => false,
			'SETTINGS_HUB_URL' => $url->getUri(),
			'MODE' => $mode,
			'ENTITY_TYPE_ID' => EntityPreset::Requisite,
		];
		if ($mode === 'edit')
		{
			$presetId = $request->getQuery('preset_id');
			$parameters['PRESET_ID'] = (is_string($presetId) || is_int($presetId))
				&& preg_match('/^[0-9]+$/D', (string)$presetId) === 1
				? (filter_var($presetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0)
				: 0;
		}

		return new Component('bitrix:crm.config.preset', $parameters, '.default');
	}
}
