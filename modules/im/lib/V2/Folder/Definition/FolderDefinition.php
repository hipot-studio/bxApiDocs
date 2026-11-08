<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Definition;

/**
 * Composition model of a folder: turns it into its REST shape, into the recent
 * {@see Selection} that drives the list/counter/pins, and answers chat
 * membership. One implementation per folder kind (section, explicit chat list,
 * composite, unsupported).
 */
interface FolderDefinition
{
	public function toRestFormat(): array;

	public function toSelection(): Selection;

	/**
	 * Whether the folder exposes a standard recent list (tail). Showcase/unknown
	 * folders have none and answer false - the recent provider then reports
	 * FOLDER_OPERATION_NOT_SUPPORTED instead of running an empty query.
	 */
	public function supportsTail(): bool;

	public function containsChat(int $chatId): bool;
}
