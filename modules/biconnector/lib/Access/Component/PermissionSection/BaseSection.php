<?php

namespace Bitrix\BIConnector\Access\Component\PermissionSection;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * What every section of the rights screen shares: its phrases, its right of editing and an empty
 * set of checks before the save.
 */
abstract class BaseSection implements PermissionSection
{
	/**
	 * Action of the right that opens the section for editing.
	 */
	abstract protected function getEditAction(): string;

	public function getTitle(): string
	{
		self::loadSectionPhrases();

		return (string)Loc::getMessage('BICONNECTOR_CONFIG_PERMISSION_' . $this->getCode());
	}

	public function getHint(): string
	{
		self::loadSectionPhrases();

		return (string)Loc::getMessage('HINT_BICONNECTOR_CONFIG_PERMISSION_' . $this->getCode());
	}

	public function canEdit(): bool
	{
		return AccessController::getCurrent()->check($this->getEditAction());
	}

	public function canAddGroup(): bool
	{
		return false;
	}

	public function preSaveChecks(array $userGroups): Result
	{
		return new Result();
	}

	/**
	 * The phrases of all three sections live in the lang file of PermissionConfig, where they were
	 * written before the sections became objects. Loading them from here keeps one file per locale
	 * instead of four copies of the same three titles.
	 */
	private static function loadSectionPhrases(): void
	{
		Loc::loadMessages(dirname(__DIR__) . '/PermissionConfig.php');
	}
}
