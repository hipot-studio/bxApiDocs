<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Sort;

use Bitrix\Im\V2\Folder\FolderCollection;
use Bitrix\Im\V2\Result;

/**
 * Result of {@see FolderSortService::saveFolderList}: every folder available to the user once the
 * save is over, in the order the server keeps them in - the answer of the operation as it is
 * published (DTO-03), the same shape {@see \Bitrix\Im\V2\Folder\Visibility\HideResult} carries.
 *
 * The whole set and not the folders the save touched: the clients apply it as a snapshot of their
 * collection and drop a folder missing from it. The new composition of the general list rides
 * inside the ordinary object of "All" and the order rides inside each folder - neither is given a
 * field of its own, because a second place to publish them from could disagree with the set.
 *
 * @extends Result<FolderCollection>
 */
class SaveListResult extends Result
{
	/**
	 * @var string[]|null stored codes of the sources the save switched back on, null while the
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
	 * Never leaves the server: it is read by the transport boundary to build the label of the save
	 * and takes no part in the answer, which tells the new composition of "All" by the object of
	 * that folder.
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
	 * Null and the empty array differ: null means the save never reached the invariant and nothing
	 * was written, the empty array means it did and no source had to come back.
	 *
	 * @return string[]|null
	 */
	public function getReturnedSourceCodes(): ?array
	{
		return $this->returnedSourceCodes;
	}
}
