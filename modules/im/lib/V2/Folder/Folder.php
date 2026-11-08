<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\V2\AccessCheckable;
use Bitrix\Im\V2\ActiveRecord;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Folder\Definition\FolderDefinition;
use Bitrix\Im\V2\Rest\RestEntity;

interface Folder extends AccessCheckable, RestEntity, ActiveRecord
{
	public function getId(): ?int;

	public function getUserId(): ?int;

	public function getType(): string;

	public function getCode(): ?string;

	public function getTitle(): string;

	public function getSort(): int;

	public function getRecentSection(): ?string;

	public function getParentId(): int;

	public function containsChat(Chat $chat): bool;

	public function displaysNestedInRoot(): bool;

	public function getRecentParentScope(): ?int;

	/**
	 * Composition model driving the folder's recent list/counter/pins and REST shape.
	 * Public so the recent provider can route the tail through the definition
	 * ({@see FolderDefinition::toSelection}, {@see FolderDefinition::supportsTail}).
	 */
	public function getDefinition(): FolderDefinition;

	/**
	 * Composition the folder is searched by ({@see \Bitrix\Im\V2\Folder\Query\FolderSearchScope}).
	 * By default it is {@see self::getDefinition()} itself. "All" is the exception: it is searched by
	 * every source available to its owner, whether or not the source is enabled in the stored
	 * composition, so that a chat of a switched-off source can still be found.
	 */
	public function getSearchDefinition(): FolderDefinition;
}
