<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Mail\Internals\MessageAccessTable;

/**
 * Filter presets as Options reads them. Options resolves the pinned preset out of this declaration,
 * so the screen and the pages behind it have to declare the same set: a side that declares nothing
 * falls back to the plain default filter and selects across every folder of the mailbox.
 *
 * Names are absent on purpose - only the screen renders or saves a preset, and the phrases live there.
 */
final class FilterPresetsDeclaration
{
	public const PRESET_INCOME = 'income';
	public const PRESET_OUTCOME = 'outcome';
	public const PRESET_SPAM = 'spam';
	public const PRESET_TRASH = 'trash';
	public const PRESET_BIND_TASK = 'bindTask';
	public const PRESET_BIND_CRM = 'bindCrm';
	public const PRESET_BIND_POST = 'bindPost';

	/**
	 * Folder presets before the unsynced folders are dropped: the screen keeps the whole declaration
	 * to tell its own presets from the ones the person made.
	 *
	 * @return array<string, array>
	 */
	public function directories(MailboxDirectoryHelper $dirsHelper, bool $isAllMailMode): array
	{
		if ($isAllMailMode)
		{
			// a folder of one mailbox narrows nothing in a list spanning all of them
			return [];
		}

		return [
			self::PRESET_INCOME => [
				'default' => 'true',
				'fields' => ['DIR' => $dirsHelper->getDefaultDirPath(true)],
			],
			self::PRESET_OUTCOME => ['fields' => ['DIR' => $dirsHelper->getOutcomePath(true)]],
			self::PRESET_SPAM => ['fields' => ['DIR' => $dirsHelper->getSpamPath(true)]],
			self::PRESET_TRASH => ['fields' => ['DIR' => $dirsHelper->getTrashPath(true)]],
		];
	}

	/**
	 * @return array<string, array>
	 */
	public function bindings(): array
	{
		return [
			self::PRESET_BIND_TASK => ['fields' => ['BIND' => MessageAccessTable::ENTITY_TYPE_TASKS_TASK]],
			self::PRESET_BIND_CRM => ['fields' => ['BIND' => MessageAccessTable::ENTITY_TYPE_CRM_ACTIVITY]],
			self::PRESET_BIND_POST => ['fields' => ['BIND' => MessageAccessTable::ENTITY_TYPE_BLOG_POST]],
		];
	}

	/**
	 * The set a screen ends up with: a folder preset survives only while the mailbox syncs its folder.
	 *
	 * @return array<string, array>
	 */
	public function build(MailboxDirectoryHelper $dirsHelper, bool $isAllMailMode): array
	{
		$presets = [];

		foreach ($this->directories($dirsHelper, $isAllMailMode) as $key => $preset)
		{
			$directory = $dirsHelper->getDirByPath($preset['fields']['DIR']);

			if ($directory !== null && $directory->isSync())
			{
				$presets[$key] = $preset;
			}
		}

		return $presets + $this->bindings();
	}
}
