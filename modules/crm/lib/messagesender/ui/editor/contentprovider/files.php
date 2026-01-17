<?php

namespace Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;

use Bitrix\Crm\Integration\Bitrix24Manager;
use Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;
use Bitrix\Main\Loader;

final class Files extends ContentProvider
{
	public function getKey(): string
	{
		return 'files';
	}

	public function isShown(): bool
	{
		return Loader::includeModule('disk') && \Bitrix\Disk\Configuration::isPossibleToShowExternalLinkControl();
	}

	public function isEnabled(): bool
	{
		return $this->isShown() && \Bitrix\Disk\Configuration::isEnabledManualExternalLink();
	}

	public function isLocked(): bool
	{
		return $this->isShown() && Bitrix24Manager::isEnabled() && !$this->isEnabled();
	}

	public function jsonSerialize(): array
	{
		$json = parent::jsonSerialize();
		if ($this->isLocked())
		{
			$json['sliderCode'] = 'limit_office_share_file';
		}

		return $json;
	}
}
