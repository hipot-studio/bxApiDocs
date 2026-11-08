<?php

namespace Bitrix\ImMobile\NavigationTab\Tab;

use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\ImMobile\NavigationTab\MessengerComponentTitle;
use Bitrix\Main\Localization\Loc;

class VibecodeApp extends BaseRecent
{
	use MessengerComponentTitle;

	public function __construct(private readonly ?Folder $folder = null)
	{
		parent::__construct();
	}

	public function isAvailable(): bool
	{
		return $this->folder instanceof SystemFolder
			&& $this->folder->isAvailable((int)$this->folder->getUserId())
		;
	}

	public function isPreload(): bool
	{
		return false;
	}

	protected function getParams(): array
	{
		return [];
	}

	protected function getWidgetSettings(): array
	{
		return [
			'useSearch' => true,
			'preload' => $this->isPreload(),
			'titleParams' => [
				'useLargeTitleMode' => true,
				'text' => $this->getTitle(),
			],
		];
	}

	public function getId(): string
	{
		return 'vibecodeApp';
	}

	public function getTabTitle(): ?string
	{
		return Loc::getMessage('IMMOBILE_NAVIGATION_TAB_VIBECODE_APP_TAB_TITLE');
	}

	public function getComponentCode(): string
	{
		return '';
	}

	protected function getComponentName(): string
	{
		return '';
	}

	protected function isWidgetSupported(): bool
	{
		return true;
	}
}
