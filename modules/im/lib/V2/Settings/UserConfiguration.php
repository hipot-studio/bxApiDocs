<?php

namespace Bitrix\Im\V2\Settings;

use Bitrix\Im\Configuration\Configuration;
use Bitrix\Im\Configuration\General;
use Bitrix\Im\Configuration\Manager;
use Bitrix\Im\Model\OptionUserTable;
use Bitrix\Im\Recent;
use Bitrix\Im\V2\Analytics\SettingsAnalytics;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Settings\Preset\Preset;
use Bitrix\Im\V2\Settings\Preset\PresetError;

class UserConfiguration
{

	protected Preset $notifyPreset;
	protected Preset $generalPreset;

	private ?int $userId;

	public function __construct(?int $userId = null)
	{
		if ($userId !== null)
		{
			$this->load($userId);
		}
	}

	public function load(int $userId): Result
	{
		$this->userId = $userId;

		$result = new Result();
		$cache = CacheManager::getUserCache($userId);
		$bindings = $cache->getValue();
		if (
			!empty($bindings[CacheManager::GENERAL_PRESET])
			&& !empty($bindings[CacheManager::NOTIFY_PRESET])
		)
		{
			$this->generalPreset = Preset::getInstance($bindings[CacheManager::GENERAL_PRESET]);
			$this->notifyPreset = Preset::getInstance($bindings[CacheManager::NOTIFY_PRESET]);

			return $result->setResult(true);
		}

		$bindingsUser =
			OptionUserTable::query()
				->addSelect('NOTIFY_GROUP_ID')
				->addSelect('GENERAL_GROUP_ID')
				->where('USER_ID', $userId)
				->setLimit(1)
		 		->fetchObject()
		;
		if ($bindingsUser)
		{
			$bindings = [
				CacheManager::GENERAL_PRESET => $bindingsUser->getGeneralGroupId(),
				CacheManager::NOTIFY_PRESET => $bindingsUser->getNotifyGroupId(),
			];
		}

		if (!$bindings)
		{
			$presetId = Configuration::restoreBindings($userId);

			$bindings = [
				CacheManager::GENERAL_PRESET => $presetId,
				CacheManager::NOTIFY_PRESET => $presetId,
			];
		}

		$this->generalPreset = Preset::getInstance($bindings[CacheManager::GENERAL_PRESET]);
		$this->notifyPreset = Preset::getInstance($bindings[CacheManager::NOTIFY_PRESET]);

		$cache->setValue([
			CacheManager::NOTIFY_PRESET => $this->notifyPreset->getId(),
			CacheManager::GENERAL_PRESET => $this->generalPreset->getId(),
		]);

		return $result->setResult(true);
	}

	public function updateGeneralSetting(array $settingsConfiguration)
	{
		$settingsBeforeUpdate = ($settingsConfiguration['name'] === 'pinnedChatSort')
			? $this->getGeneralSettings()
			: null
		;

		$mainReactionBeforeUpdate = ($settingsConfiguration['name'] === Entity\General::DEFAULT_REACTION)
			? ($this->getGeneralSettings()[Entity\General::DEFAULT_REACTION] ?? null)
			: null
		;

		$soundSettingsBeforeUpdate = $this->isSoundWrite($settingsConfiguration['name'])
			? $this->getGeneralSettings()
			: null
		;
		$presetBeforeUpdateId = $this->generalPreset->getId();

		if (!$this->generalPreset->isPersonal($this->userId))
		{
			$personalPreset = Preset::getPersonal($this->userId);

			if ($personalPreset->isExist())
			{
				$this->generalPreset = $personalPreset;
			}
			else
			{
				$personalPreset = Preset::getInstance();
				$personalPreset->initPersonal($this->userId);
				$this->generalPreset = $personalPreset;
			}

			$this->generalPreset->bindToUser($this->userId, [Preset::BIND_GENERAL]);
			CacheManager::getUserCache($this->userId)->clearCache();
		}

		if ($soundSettingsBeforeUpdate === null)
		{
			$storedSettings = [$settingsConfiguration['name'] => $settingsConfiguration['value']];
			$this->generalPreset->general->updateSetting($settingsConfiguration);
		}
		else
		{
			$storedSettings = $this->prepareSoundSettings(
				$settingsConfiguration,
				$soundSettingsBeforeUpdate,
				$this->generalPreset->getId() === $presetBeforeUpdateId
			);
			$this->generalPreset->general->updateSettings($storedSettings);
		}

		// Other clients get what was actually stored: a write of the legacy sound flag keeps
		// nothing under that name, only the mute flag and the levels it had to raise.
		Manager::sendPullGeneralSettingsUpdate($this->userId, $storedSettings);
		$this->perfomSideEffect($settingsConfiguration, $settingsBeforeUpdate);
		$this->sendMainReactionAnalytics($settingsConfiguration, $mainReactionBeforeUpdate);

		if (!$this->generalPreset->general->shouldUpdateSimpleNotifySettings($settingsConfiguration))
		{
			CacheManager::getPresetCache($this->generalPreset->getId())->clearCache();

			return;
		}
		if (!$this->notifyPreset->isPersonal($this->userId))
		{
			$this->notifyPreset = $this->generalPreset;
			$this->notifyPreset->bindToUser($this->userId, [Preset::BIND_NOTIFY]);
			CacheManager::getUserCache($this->userId)->clearCache();
		}

		$simpleSchema = $this->generalPreset->general->getSimpleNotifyScheme();
		$this->notifyPreset->notify->updateSimpleSettings($simpleSchema);

		CacheManager::getPresetCache($this->notifyPreset->getId())->clearCache();
	}

	/**
	 * @param array{moduleId: string, name: string, type: string, value: bool} $settingConfiguration
	 * @return void
	 */
	public function updateNotifySetting(array $settingsConfiguration)
	{
		if (!$this->notifyPreset->isPersonal($this->userId))
		{
			$notifyPreset = Preset::getPersonal($this->userId);

			if (!$notifyPreset->isExist())
			{
				$notifyPreset = Preset::getInstance();
				$notifyPreset->initPersonal($this->userId);
			}
			$this->notifyPreset = $notifyPreset;

			$this->notifyPreset->bindToUser($this->userId, [Preset::BIND_NOTIFY]);
			CacheManager::getUserCache($this->userId)->clearCache();
		}

		$this->notifyPreset->notify->updateSetting($settingsConfiguration);

		CacheManager::getPresetCache($this->notifyPreset->getId())->clearCache();
	}

	public function updateStatus(string $status): bool
	{
		$this->updateGeneralSetting(['name' => 'status', 'value' => $status]);

		return \CIMStatus::Set($this->userId, ['STATUS' => $status]);
	}

	public function getGeneralSettings(): array
	{
		if ($this->generalPreset->getId() === null)
		{
			$this->recoveryBinding(Preset::BIND_GENERAL);
		}

		if ($this->generalPreset->general === null)
		{
			$this->generalPreset = Preset::getDefaultPreset();
		}

		return $this->generalPreset->general->toRestFormat();
	}

	public function getNotifySettings(): array
	{
		if ($this->notifyPreset->getId() === null)
		{
			$this->recoveryBinding(Preset::BIND_NOTIFY);
		}

		if ($this->notifyPreset->notify === null)
		{
			$this->notifyPreset = Preset::getDefaultPreset();
		}

		return $this->notifyPreset->notify->toRestFormat();
	}

	protected function perfomSideEffect(array $settingConfiguration, ?array $settingsBeforeUpdate)
	{
		$this->updateUserSearch($settingConfiguration);

		if (isset($settingsBeforeUpdate))
		{
			$this->updatePinSortCost($settingConfiguration, $settingsBeforeUpdate);
		}
	}

	/**
	 * Fires the analytics event once per actual main-reaction change. All entry points
	 * (message picker, settings; web and mobile) write through this method, so a single
	 * point covers them without double-counting the cross-device settingsUpdate pull.
	 */
	private function sendMainReactionAnalytics(array $settingsConfiguration, ?string $reactionBeforeUpdate): void
	{
		if ($settingsConfiguration['name'] !== Entity\General::DEFAULT_REACTION)
		{
			return;
		}

		// Compare against the persisted (normalized) value, exactly as the write path stores it.
		$persistedReaction = General::checkingValues(
			[Entity\General::DEFAULT_REACTION => $settingsConfiguration['value']]
		)[Entity\General::DEFAULT_REACTION] ?? null;

		if ($persistedReaction === null || $persistedReaction === $reactionBeforeUpdate)
		{
			return;
		}

		(new SettingsAnalytics())->addSetMainReaction((int)$this->userId, (string)$persistedReaction);
	}

	private function isSoundWrite(string $name): bool
	{
		return $name === Entity\General::ENABLE_SOUND || Sound\VolumeLevels::isSoundKey($name);
	}

	/**
	 * Sound is stored as a whole set: a write of the legacy flag turns into the mute flag plus the
	 * levels it has to raise, and the keys a write leaves out are brought along by the rule in
	 * soundNamesToCarry().
	 */
	private function prepareSoundSettings(
		array $settingsConfiguration,
		array $currentSettings,
		bool $keptThePreset
	): array
	{
		$name = $settingsConfiguration['name'];

		$changed = $name === Entity\General::ENABLE_SOUND
			? Sound\VolumeLevels::fromLegacyWrite(
				Sound\VolumeLevels::isLegacySoundEnabled($settingsConfiguration['value']),
				$currentSettings
			)
			: [$name => $settingsConfiguration['value']]
		;

		$soundSettings = Sound\VolumeLevels::fill($currentSettings);
		$settings = [];
		foreach ($this->soundNamesToCarry($keptThePreset, $name) as $soundName)
		{
			if (!array_key_exists($soundName, $changed))
			{
				$settings[$soundName] = $soundSettings[$soundName];
			}
		}

		return array_replace($settings, $changed);
	}

	/**
	 * The sound keys a write has to bring along so the storage holds the whole state the user had.
	 * A write that moved him to a preset of his own left the sound behind: the new preset is filled
	 * with the defaults and knows nothing of what he heard, so all of it goes into it. A preset he
	 * already had misses only the keys it has no row for. A write of the legacy flag brings nothing:
	 * it says what it means, and the keys it leaves out are derived from the flag stored next to them.
	 *
	 * @return string[]
	 */
	private function soundNamesToCarry(bool $keptThePreset, string $writtenName): array
	{
		if (!$keptThePreset)
		{
			return Sound\VolumeLevels::soundKeys();
		}

		if ($writtenName === Entity\General::ENABLE_SOUND)
		{
			return [];
		}

		return array_diff(
			Sound\VolumeLevels::soundKeys(),
			General::getStoredSettingNames((int)$this->generalPreset->getId())
		);
	}

	private function updateUserSearch(array $settingsConfiguration): void
	{
		$defaultSettings = General::getDefaultSettings();

		if (
			$settingsConfiguration['name'] === Entity\General::PRIVACY_SEARCH
			&& $this->checkUserSearch($settingsConfiguration['value'])
		)
		{
			$value =
				$defaultSettings[Entity\General::PRIVACY_SEARCH] === $settingsConfiguration['value']
					? ''
					: $settingsConfiguration['value']
			;

			\Bitrix\Main\Application::getUserTypeManager()->Update(
				"USER",
				$this->userId,
				[
					'UF_IM_SEARCH' => $value
				]
			);
		}
	}

	private function checkUserSearch($settingValue): bool
	{
		return in_array($settingValue, [General::PRIVACY_RESULT_ALL, General::PRIVACY_RESULT_CONTACT], true);
	}

	private function recoveryBinding(string $toEntity)
	{
		$userPreset = Preset::getPersonal($this->userId);

		$bindingPreset =
			$userPreset->isExist()
				? $userPreset
				: Preset::getDefaultPreset()
		;
		$bindingPreset->bindToUser($this->userId, [$toEntity]);

		if ($toEntity === Preset::BIND_GENERAL)
		{
			$this->generalPreset = $bindingPreset;
		}
		else
		{
			$this->notifyPreset = $bindingPreset;
		}

		CacheManager::getUserCache($this->userId)->clearCache();
	}

	public function checkIsPersonalGeneralPreset(): bool
	{
		return $this->generalPreset->isPersonal($this->userId);
	}

	public function getPersonalGeneralPresetId(): ?int
	{
		return $this->generalPreset->getId();
	}

	private function updatePinSortCost(array $settingsConfiguration, array $settingsBeforeUpdate): void
	{
		if ($settingsConfiguration['name'] === 'pinnedChatSort'
			&& $settingsConfiguration['value'] !== 'byDate'
		)
		{
			if ($settingsBeforeUpdate['pinnedChatSort'] !== 'byCost')
			{
				Recent::updatePinSortCost($this->userId);
			}
		}
	}
}