<?php

namespace Bitrix\Crm\Component\EntityDetails\TimelineMenuBar\Item;

use Bitrix\Crm\Component\EntityDetails\TimelineMenuBar\Item;
use Bitrix\Crm\V2\Internal\Service\Activity\Sync\SyncAvailability;
use Bitrix\Main\Localization\Loc;

class Sync extends Item
{
	private ?SyncAvailability $syncAvailability = null;

	public function getId(): string
	{
		return 'sync';
	}

	public function getName(): string
	{
		return Loc::getMessage('CRM_TIMELINE_SYNC_TITLE');
	}

	public function isNew(): bool
	{
		return true;
	}

	public function isAvailable(): bool
	{
		if (!in_array($this->getEntityTypeId(), SyncAvailability::SUPPORTED_ENTITY_TYPE_IDS, true))
		{
			return false;
		}

		return $this->isFeatureEnabled()
			&& $this->isCallModuleInstalled()
			&& $this->isSyncPresetEnabled()
		;
	}

	protected function isFeatureEnabled(): bool
	{
		return $this->getSyncAvailability()->isFeatureEnabled();
	}

	protected function isCallModuleInstalled(): bool
	{
		return $this->getSyncAvailability()->isCallModuleAvailable();
	}

	protected function isSyncPresetEnabled(): bool
	{
		return $this->getSyncAvailability()->isSyncPresetEnabled();
	}

	private function getSyncAvailability(): SyncAvailability
	{
		$this->syncAvailability ??= new SyncAvailability();

		return $this->syncAvailability;
	}
}
