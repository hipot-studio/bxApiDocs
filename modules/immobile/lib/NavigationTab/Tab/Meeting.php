<?php

namespace Bitrix\ImMobile\NavigationTab\Tab;

use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\ImMobile\NavigationTab\MessengerComponentTitle;
use Bitrix\Main\Localization\Loc;

class Meeting extends BaseRecent
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

	/**
	 * The client knows the meetings list as 'calendar': the same id already stands for the
	 * recent section of the folder and for the meetings tab of a project.
	 */
	public function getId(): string
	{
		return 'calendar';
	}

	public function getTabTitle(): ?string
	{
		return Loc::getMessage('IMMOBILE_NAVIGATION_TAB_MEETING_TAB_TITLE');
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
