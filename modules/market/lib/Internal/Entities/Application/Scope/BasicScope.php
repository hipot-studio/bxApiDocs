<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Entities\Application\Scope;

use Bitrix\Main;

class BasicScope
{
	protected static bool $areLocMessagesLoaded = false;

	protected ?string $description = null;
	protected ?string $icon = null;
	protected ?string $title = null;

	public function __construct(
		protected string $key,
		protected string $name,
	)
	{
		static::loadLocMessages();
	}

	public function getKey(): string
	{
		return $this->key;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getTitle(): string
	{
		return $this->title ??= $this->getLocMessage();
	}

	public function getDescription(): string
	{
		return $this->description ??= $this->getLocMessage('_DESCRIPTION');
	}

	public function getIcon(): string
	{
		if ($this->icon === null)
		{
			$strippedName = str_replace('/', '', mb_strtolower($this->getKey()));
			$this->icon = '/bitrix/images/market/scope/market-icon-' . $strippedName . '.svg';
		}

		return $this->icon;
	}

	public function toArray(): array
	{
		return [
			'CODE' => $this->getKey(),
			'TITLE' => $this->getTitle(),
			'DESCRIPTION' => $this->getDescription(),
			'ICON' => $this->getIcon(),
		];
	}

	protected static function loadLocMessages(): void
	{
		if (!static::$areLocMessagesLoaded)
		{
			$locMessagesPath = Main\IO\Path::combine(
				Main\Application::getDocumentRoot(),
				BX_ROOT,
				'modules/rest/scope.php'
			);

			Main\Localization\Loc::loadMessages($locMessagesPath);

			static::$areLocMessagesLoaded = true;
		}
	}

	protected function getLocMessage(?string $suffix = null): string
	{
		$locKey = mb_strtoupper('REST_SCOPE_' . $this->getKey() . ($suffix ?? ''));

		return Main\Localization\Loc::getMessage($locKey) ?? '';
	}
}
