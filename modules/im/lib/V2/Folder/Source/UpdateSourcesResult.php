<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Source;

use Bitrix\Im\V2\Result;

/**
 * Result of {@see FolderSourceService::updateSources}: the whole composition stored for the
 * folder after the save, in the canonical registry order (API-02).
 *
 * It is the same list the folderUpdate push carries inside folder.definition (EVENT-01) - both
 * are read from one re-read folder, so the client applying the answer and then its own push
 * cannot end up with two different compositions.
 *
 * @extends Result<string[]>
 */
class UpdateSourcesResult extends Result
{
	/**
	 * @var string[]|null stored codes of the sources the user left disabled, null while the
	 *      composition was never counted
	 */
	private ?array $disabledSourceCodes = null;

	/**
	 * @param string[] $sourceFolders
	 */
	public function setSourceFolders(array $sourceFolders): self
	{
		$this->setResult($sourceFolders);

		return $this;
	}

	/**
	 * @return string[]
	 */
	public function getSourceFolders(): array
	{
		return $this->getResult() ?? [];
	}

	/**
	 * Never leaves the server: it is read by the transport boundary to build the label of the save
	 * and takes no part in the answer of the action.
	 *
	 * @param string[] $codes stored codes, the report values are resolved later
	 *        ({@see \Bitrix\Im\V2\Analytics\FolderAnalytics})
	 */
	public function setDisabledSourceCodes(array $codes): self
	{
		$this->disabledSourceCodes = $codes;

		return $this;
	}

	/**
	 * Null and the empty array differ: null means the composition was never counted and nothing was
	 * written, the empty array means nothing is left disabled.
	 *
	 * @return string[]|null
	 */
	public function getDisabledSourceCodes(): ?array
	{
		return $this->disabledSourceCodes;
	}
}
