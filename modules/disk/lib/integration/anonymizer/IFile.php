<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Provider;

/**
 * Provider work with files
 */
interface IFile
{
	public function __construct(int $fileId);

	/**
	 * Save replaces in new file (if changed).
	 * @return int - ID of new file. If no replacements applied - get original ID
	 */
	public function save(): int;
}