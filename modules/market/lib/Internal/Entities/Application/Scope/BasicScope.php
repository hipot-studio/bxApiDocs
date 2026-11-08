<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Entities\Application\Scope;

use Bitrix\Main;

class BasicScope
{
	private const ICON_ALIASES = [
		'sign.b2e' => 'userconsent',
	];

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
		return $this->title ??= $this->getLocMessage() ?? ($this->getName() ?: $this->getKey());
	}

	public function getDescription(): string
	{
		return $this->description ??= $this->getLocMessage('_DESCRIPTION') ?? '';
	}

	public function getIcon(): string
	{
		if ($this->icon === null)
		{
			$scopeKey = mb_strtolower($this->getKey());
			$iconName = self::ICON_ALIASES[$scopeKey] ?? str_replace('/', '', $scopeKey);
			$this->icon = '/bitrix/images/market/scope/market-icon-' . $iconName . '.svg';
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

	/**
	 * Resolves legacy dotted keys first, then falls back to keys with dots replaced by underscores.
	 * Each key form has an independent consecutive MSGVER chain; any legacy phrase takes precedence. New fallback
	 * phrases belong to the mirrored language file for this class.
	 */
	protected function getLocMessage(?string $suffix = null): ?string
	{
		$baseKey = mb_strtoupper('REST_SCOPE_' . $this->getKey() . ($suffix ?? ''));
		$message = static::getLastVersionedLocMessage($baseKey);
		$normalizedBaseKey = str_replace('.', '_', $baseKey);

		if ($message !== null || $normalizedBaseKey === $baseKey)
		{
			return $message;
		}

		return static::getLastVersionedLocMessage($normalizedBaseKey);
	}

	protected static function getLastVersionedLocMessage(string $baseKey): ?string
	{
		$message = static::getLocMessageByKey($baseKey);
		$version = 1;

		while (($versionedMessage = static::getLocMessageByKey($baseKey . '_MSGVER_' . $version)) !== null)
		{
			$message = $versionedMessage;
			$version++;
		}

		return $message;
	}

	protected static function getLocMessageByKey(string $locKey): ?string
	{
		$message = Main\Localization\Loc::getMessage($locKey);

		return ($message === null || $message === '') ? null : $message;
	}
}
