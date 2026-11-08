<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Source;

/**
 * One item of the composition screen (DTO-01): a source of a composite folder the user may
 * switch, or a showcase source shown permanently locked.
 *
 * The item carries no text: the client takes the title and the subtitle from the folder
 * object it is bound to, so nothing is duplicated between the two collections of the answer
 * ({@see SourceList}). The bond is {@see SourceItem::$folderId} and nothing else - the code
 * of a showcase item is the wire code of its folder ('lines'), not the stored one
 * ('openlines').
 */
final readonly class SourceItem
{
	/**
	 * @param int $folderId id of the source folder of this user
	 * @param string $code wire code of the source folder ({@see \Bitrix\Im\V2\Folder\BaseFolder::getRestCode})
	 * @param bool $enabled the source is in the stored composition of the folder
	 * @param bool $togglable the switch is active - false for a showcase source
	 */
	public function __construct(
		public int $folderId,
		public string $code,
		public bool $enabled,
		public bool $togglable,
	) {}

	public function toRestFormat(): array
	{
		return [
			'folderId' => $this->folderId,
			'code' => $this->code,
			'enabled' => $this->enabled,
			'togglable' => $this->togglable,
		];
	}
}
