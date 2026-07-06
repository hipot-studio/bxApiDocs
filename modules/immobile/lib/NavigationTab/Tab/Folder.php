<?php

namespace Bitrix\ImMobile\NavigationTab\Tab;

class Folder extends BaseRecent
{
	public function __construct(
		private readonly \Bitrix\Im\V2\Folder\Folder $folder,
	)
	{
		parent::__construct();
	}

	public function isAvailable(): bool
	{
		return true;
	}

	public function isPreload(): bool
	{
		return false;
	}

	public function getId(): string
	{
		return (string)$this->folder->getId();
	}

	public function getTabTitle(): ?string
	{
		return $this->folder->getTitle();
	}

	public function getComponentCode(): string
	{
		return '';
	}

	protected function getComponentName(): string
	{
		return '';
	}

	protected function getParams(): array
	{
		return [];
	}

	protected function getWidgetSettings(): array
	{
		return [
			'useSearch'   => true,
			'preload'     => $this->isPreload(),
			'titleParams' => [
				'useLargeTitleMode' => true,
				'text'              => $this->getTabTitle(),
			],
		];
	}

	protected function isWidgetSupported(): bool
	{
		return true;
	}
}
