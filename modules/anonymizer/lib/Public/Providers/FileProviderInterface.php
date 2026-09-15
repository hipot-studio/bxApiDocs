<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Public\Providers;

/**
 * File-backed provider: textual content for anonymize_text and persistence after apply.
 * Disk integration: {@see getFileId()} and {@see save()} use Disk object ID ({@code b_disk_object.ID}).
 */
interface FileProviderInterface extends ProviderInterface
{
	/**
	 * Returns flat text extracted from the file for anonymize_text command.
	 */
	public function getContent(): string;

	/**
	 * Persists document when it was changed.
	 * Returns Disk object ID after save, or the original ID if unchanged / save failed.
	 */
	public function save(): int;

	/**
	 * Returns Disk object ID ({@code b_disk_object.ID}) this provider operates on.
	 */
	public function getFileId(): int;
}
