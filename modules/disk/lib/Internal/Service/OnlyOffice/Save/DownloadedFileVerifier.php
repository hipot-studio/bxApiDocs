<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Save;

use Bitrix\Main\Error;
use Bitrix\Main\IO\File;
use Bitrix\Main\Result;

/**
 * Tells a whole downloaded document from a truncated one before it becomes a version.
 *
 * A document cut short by a broken connection still looks like a file: the checks go from the cheapest
 * evidence to the strongest one the format allows, and the first failure stops them. The contents are
 * never unpacked and never checksummed - the point is the integrity of the transfer, not of the document.
 */
final class DownloadedFileVerifier
{
	public const DATA_SKIPPED_CONTAINER_CHECK = 'skippedContainerCheck';

	private const CONTAINER_EXTENSIONS = [
		'docx',
		'docm',
		'dotx',
		'xlsx',
		'xlsm',
		'xltx',
		'pptx',
		'pptm',
		'potx',
		'ppsx',
		'odt',
		'ods',
		'odp',
	];

	/**
	 * @param int|null $expectedContentLength size the editor server announced, or null when it announced none.
	 * @param string|null $callbackFileType format from the save callback; the extension of the object is the
	 *        fallback when the callback names none.
	 */
	public function verify(
		string $filePath,
		?int $expectedContentLength = null,
		?string $callbackFileType = null,
		?string $objectExtension = null,
	): Result
	{
		$file = new File($filePath);
		$actualSize = $file->isExists() ? (int)$file->getSize() : 0;

		if ($actualSize <= 0)
		{
			return $this->fail('Downloaded document is empty.');
		}

		if ($expectedContentLength !== null && $expectedContentLength !== $actualSize)
		{
			return $this->fail(
				"Downloaded document is {$actualSize} bytes against the announced {$expectedContentLength}."
			);
		}

		if (!$this->isContainerFormat($callbackFileType, $objectExtension))
		{
			return $this->succeed(skippedContainerCheck: false);
		}

		if (!extension_loaded('zip'))
		{
			return $this->succeed(skippedContainerCheck: true);
		}

		$archive = new \ZipArchive();
		$opened = $archive->open($filePath, \ZipArchive::CHECKCONS);
		if ($opened !== true)
		{
			return $this->fail("Downloaded document is a broken container. Code: {$opened}.");
		}

		$archive->close();

		return $this->succeed(skippedContainerCheck: false);
	}

	private function isContainerFormat(?string $callbackFileType, ?string $objectExtension): bool
	{
		$extension = trim((string)$callbackFileType);
		if ($extension === '')
		{
			$extension = trim((string)$objectExtension);
		}

		return in_array(strtolower($extension), self::CONTAINER_EXTENSIONS, true);
	}

	private function succeed(bool $skippedContainerCheck): Result
	{
		return (new Result())->setData([
			self::DATA_SKIPPED_CONTAINER_CHECK => $skippedContainerCheck,
		]);
	}

	private function fail(string $message): Result
	{
		return (new Result())
			->addError(new Error($message, SaveFailureCategory::IncompleteFile->value))
			->setData([self::DATA_SKIPPED_CONTAINER_CHECK => false])
		;
	}
}
