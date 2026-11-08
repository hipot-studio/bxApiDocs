<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\Main\Service;

/**
 * Reads the personal hotkey bindings the editor screen hands to the frontend.
 *
 * With no personal record CUserOptions answers with the portal-wide option (COMMON = 'Y', USER_ID = 0)
 * an administrator may have set: that fallback is the core read rule, not something added here.
 */
class HotkeysOptionsService
{
	/**
	 * Contract with the frontend: these mirror HOTKEYS_OPTION_CATEGORY / HOTKEYS_OPTION_NAME in
	 * bizprocdesigner/install/js/bizprocdesigner/editor/chart/src/entities/hotkeys/api/hotkeys-api.js
	 * (branch bp/task-737919, not merged here yet). Renaming either side silently disables the feature,
	 * so keep both in sync.
	 */
	private const OPTION_CATEGORY = 'bizprocdesigner.hotkeys';
	private const OPTION_NAME = 'bindings';

	/**
	 * @param int|null $userId null when there is no current user; personal bindings do not exist then.
	 *
	 * @return array|null the stored value as is, null when there is no user, when nothing was ever
	 *         stored (the portal-wide option included) or when what was stored is not an array.
	 *         The shape of the value belongs to the frontend and is not interpreted here.
	 */
	public function resolveBindings(?int $userId): ?array
	{
		if ($userId === null || $userId <= 0)
		{
			return null;
		}

		return $this->readStoredValue($userId);
	}

	protected function readStoredValue(int $userId): ?array
	{
		// The option is written by the schemaless main.userOption.saveOptions action, so anything can be there.
		$value = \CUserOptions::GetOption(self::OPTION_CATEGORY, self::OPTION_NAME, null, $userId);

		return is_array($value) ? $value : null;
	}
}
