<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Source;

/**
 * One item of the composition screen (DTO-01): a source of a composite folder the user may
 * switch, or a source shown locked - for good if it is a showcase one, for as long as the folder
 * stays hidden if it feeds a hidden section ({@see LockReason}).
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
	 * @param bool $togglable the switch is active - false for a showcase source and for the
	 *        source of a folder the owner has hidden
	 * @param LockReason|null $lockReason why the switch is inactive. Set together with
	 *        $togglable === false and never apart from it: the published field is what explains
	 *        the locked control to the user, and a locked control with nothing to explain it
	 *        would leave him without a way out.
	 */
	public function __construct(
		public int $folderId,
		public string $code,
		public bool $enabled,
		public bool $togglable,
		public ?LockReason $lockReason = null,
	) {}

	public function toRestFormat(): array
	{
		$item = [
			'folderId' => $this->folderId,
			'code' => $this->code,
			'enabled' => $this->enabled,
			'togglable' => $this->togglable,
		];

		// Published for a locked item only: the client reads the key as "there is a reason", and a
		// null under it would be a third state neither half of the contract describes.
		if ($this->lockReason !== null)
		{
			$item['lockReason'] = $this->lockReason->value;
		}

		return $item;
	}
}
