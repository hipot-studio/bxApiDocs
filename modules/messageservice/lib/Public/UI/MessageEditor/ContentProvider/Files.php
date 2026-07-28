<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor\ContentProvider;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\MessageService\Public\UI\MessageEditor\ContentProvider;

final class Files extends ContentProvider implements Showable
{
	private const LOCKED_SLIDER_CODE = 'limit_office_share_file';

	public function getId(): string
	{
		return 'files';
	}

	public function isShown(): bool
	{
		return Loader::includeModule('disk')
			&& \Bitrix\Disk\Configuration::isPossibleToShowExternalLinkControl();
	}

	protected function getCustomData(): array
	{
		return [
			'sliderCode' => self::LOCKED_SLIDER_CODE,
			'isLocked' => ModuleManager::isModuleInstalled('bitrix24')
				&& Loader::includeModule('disk')
				&& !\Bitrix\Disk\Configuration::isEnabledManualExternalLink(),
		];
	}
}
