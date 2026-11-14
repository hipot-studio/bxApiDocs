<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Visibility;

use Bitrix\Im\V2\Folder\FolderCollection;
use Bitrix\Im\V2\Result;

/**
 * Result of {@see FolderVisibilityService::hide}: every folder available to the user once the hide
 * is over, in the order the server keeps them in - the answer of the operation as it is published
 * (DTO-03).
 *
 * The whole set and not the folders the operation touched: the clients apply it as a snapshot of
 * their collection and drop a folder missing from it. The new composition of the general list
 * rides inside the ordinary object of "All" and gets no field of its own beside the set - the
 * composition belongs to the folder, and a second place to publish it from could disagree with it.
 *
 * @extends Result<FolderCollection>
 */
class HideResult extends Result
{
	/**
	 * @var string[]|null stored codes of the sources the hide switched back on, null while the
	 *      operation never reached the invariant
	 */
	private ?array $returnedSourceCodes = null;

	/** @var array<int, string>|null chatId => dialogId the push of the operation was built with */
	private ?array $dialogIdMap = null;

	public function setFolders(FolderCollection $folders): self
	{
		$this->setResult($folders);

		return $this;
	}

	public function getFolders(): ?FolderCollection
	{
		return $this->getResult();
	}

	/**
	 * The resolution of the dialog ids of {@see self::getFolders()} the operation has already paid
	 * for, so the answer is serialized without resolving them a second time.
	 *
	 * @param array<int, string>|null $dialogIdMap see {@see FolderCollection::buildDialogIdMap}
	 */
	public function setDialogIdMap(?array $dialogIdMap): self
	{
		$this->dialogIdMap = $dialogIdMap;

		return $this;
	}

	/**
	 * @return array<int, string>|null
	 */
	public function getDialogIdMap(): ?array
	{
		return $this->dialogIdMap;
	}

	/**
	 * Never leaves the server: it is read by the transport boundary to build the label of the hide
	 * and takes no part in the answer of the action, which tells the new composition of "All" by
	 * the object of that folder.
	 *
	 * @param string[] $codes stored codes, the report values are resolved later
	 *        ({@see \Bitrix\Im\V2\Analytics\FolderAnalytics})
	 */
	public function setReturnedSourceCodes(array $codes): self
	{
		$this->returnedSourceCodes = $codes;

		return $this;
	}

	/**
	 * Null and the empty array differ: null means the hide never reached the invariant and nothing
	 * was written, the empty array means it did and no source had to come back.
	 *
	 * @return string[]|null
	 */
	public function getReturnedSourceCodes(): ?array
	{
		return $this->returnedSourceCodes;
	}
}
