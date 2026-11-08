<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Folder\BaseFolder;
use Bitrix\Im\V2\Folder\Definition\FolderDefinition;
use Bitrix\Im\V2\Folder\Definition\SectionDefinition;
use Bitrix\Im\V2\Folder\FolderType;
use Bitrix\Main\Localization\Loc;

abstract class SystemFolder extends BaseFolder
{
	public const TYPE = FolderType::System->value;

	public function getType(): string
	{
		return self::TYPE;
	}

	/**
	 * System folder code — must match {@see \Bitrix\Im\Model\FolderTable::CODE}.
	 *
	 * Concrete subclasses MUST override and return non-null string.
	 *
	 * Note: not declared abstract because {@see BaseFolder} already provides
	 * a non-abstract implementation (returns nullable column value); PHP
	 * forbids re-declaring an inherited concrete method as abstract.
	 */
	public function getCode(): ?string
	{
		return parent::getCode();
	}

	/**
	 * Maps system folder onto recent section name. Concrete subclasses
	 * MUST override and return non-null string.
	 */
	public function getRecentSection(): ?string
	{
		return null;
	}

	/**
	 * Default position used by {@see \Bitrix\Im\V2\Folder\FolderBootstrapper::ensureSystemFolders}.
	 */
	abstract public function getDefaultPosition(): int;

	/**
	 * Lang key used by {@see SystemFolder::getTitle}.
	 */
	abstract public function getTitleLangKey(): string;

	/**
	 * Lang key used by {@see SystemFolder::getDescription}. Unlike the title, a subtitle
	 * is optional: folders without one keep the default.
	 */
	public function getDescriptionLangKey(): ?string
	{
		return null;
	}

	/**
	 * Per-user availability check — moved here from immobile/NavigationTab/Tab/*.
	 *
	 * Domain-rule (NOT a security check): availability can change between read
	 * and write (feature flag toggled, user-type changed). Treated as
	 * race-tolerant — callers degrade softly (skip / FOLDER_OPERATION_NOT_SUPPORTED)
	 * rather than failing with FOLDER_ACCESS_DENIED.
	 */
	abstract public function isAvailable(int $userId): bool;

	/**
	 * Whether legacy global pins can contain chats belonging to this folder.
	 * New sections with no legacy representation skip migration to avoid scanning every pin.
	 */
	public function shouldMigrateLegacyPins(): bool
	{
		return true;
	}

	public function containsChat(Chat $chat): bool
	{
		return $this->getDefinition()->containsChat((int)$chat->getChatId());
	}

	/**
	 * Recent section constrained to this folder's nesting frame. Subclasses that
	 * have no standard list (showcase/unsupported) override it.
	 */
	public function getDefinition(): FolderDefinition
	{
		return new SectionDefinition($this->getRecentSection(), $this->getRecentParentScope());
	}

	/**
	 * Placeholders substituted into the title phrase, e.g. ['#COPILOT_NAME#' => 'BitrixGPT'].
	 */
	public function getTitleReplacements(): array
	{
		return [];
	}

	/**
	 * For system folders title is resolved via Loc — DB column TITLE is always NULL.
	 */
	public function getTitle(): string
	{
		$message = Loc::getMessage($this->getTitleLangKey(), $this->getTitleReplacements());

		return is_string($message) && $message !== '' ? $message : '';
	}

	/**
	 * Same as the title: resolved via Loc, no DB column behind it. A missing key and a missing
	 * or empty phrase collapse to null - clients tell "no subtitle" from "empty subtitle" by it.
	 */
	public function getDescription(): ?string
	{
		$langKey = $this->getDescriptionLangKey();
		if ($langKey === null)
		{
			return null;
		}

		$message = Loc::getMessage($langKey);

		return is_string($message) && $message !== '' ? $message : null;
	}

	public function toRestFormat(array $option = []): array
	{
		return [
			'id' => $this->getId(),
			'type' => self::TYPE,
			'code' => $this->getRestCode(),
			'title' => $this->getTitle(),
			'sort' => $this->getSort(),
			'visible' => $this->isVisible(),
			'description' => $this->getDescription(),
			'displaysNestedInRoot' => $this->displaysNestedInRoot(),
			'definition' => $this->getDefinition()->toRestFormat(),
		];
	}
}
