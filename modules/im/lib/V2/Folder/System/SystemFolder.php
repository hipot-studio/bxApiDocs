<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Folder\BaseFolder;
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
	 * Per-user availability check — moved here from immobile/NavigationTab/Tab/*.
	 *
	 * Domain-rule (NOT a security check): availability can change between read
	 * and write (feature flag toggled, user-type changed). Treated as
	 * race-tolerant — callers degrade softly (skip / FOLDER_OPERATION_NOT_SUPPORTED)
	 * rather than failing with FOLDER_ACCESS_DENIED.
	 */
	abstract public function isAvailable(int $userId): bool;

	public function containsChat(Chat $chat): bool
	{
		if (!in_array($this->getRecentSection(), $chat->getRecentSections(), true))
		{
			return false;
		}

		$scope = $this->getRecentParentScope();
		$chatParent = (int)($chat->getParentChatId() ?? 0);

		return $scope === null || $scope === $chatParent;
	}

	/**
	 * For system folders title is resolved via Loc — DB column TITLE is always NULL.
	 */
	public function getTitle(): string
	{
		$message = Loc::getMessage($this->getTitleLangKey());

		return is_string($message) && $message !== '' ? $message : '';
	}

	public function toRestFormat(array $option = []): array
	{
		return [
			'id' => $this->getId(),
			'type' => self::TYPE,
			'code' => $this->getRestCode(),
			'title' => $this->getTitle(),
			'sort' => $this->getSort(),
			'displaysNestedInRoot' => $this->displaysNestedInRoot(),
			'definition' => [
				'recentSection' => $this->getRecentSection(),
			],
		];
	}
}
