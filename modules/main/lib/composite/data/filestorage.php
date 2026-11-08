<?php
namespace Bitrix\Main\Composite\Data;

use Bitrix\Main;
use Bitrix\Main\Composite\Helper;
use Bitrix\Main\Composite\Internals\PageDeleteBatch;
use Bitrix\Main\IO\File;

final class FileStorage extends AbstractStorage
{
	private const SERVICE_FILES = ["/.enabled", "/.config.php", "/.htaccess", "/.size", "/404.php"];

	/**
	 * The folder of the cache by the document root it belongs to: the root stays the same within a
	 * request, and a walk of the folder asks for it once per file it looks at
	 */
	private static array $baseDirectories = [];

	private $cacheFile = null;

	function __construct($cacheKey, array $configuration, array $htmlCacheOptions)
	{
		parent::__construct($cacheKey, $configuration, $htmlCacheOptions);

		$this->cacheFile = new Main\IO\File(Main\IO\Path::convertRelativeToAbsolute(
			Main\Application::getPersonalRoot()
			."/html_pages"
			.$this->cacheKey
		));
	}

	public function write($content, $md5)
	{
		$written = false;

		if ($this->cacheFile)
		{
			/*
			 * A temporary file of its own: two requests rendering the same page would share one,
			 * and the one that lost the race would rename a file that is not there any more
			 */
			$tempFile = new File($this->cacheFile->getPhysicalPath().".".getmypid().".tmp");

			try
			{
				$written = $tempFile->putContents($content);
				if ($tempFile->rename($this->cacheFile->getPhysicalPath()))
				{
					// the object cached the state checked before the write and would report no size at all
					$this->cacheFile = new File($this->cacheFile->getPath());

					// a rename of a missing file reports success, so the write is confirmed by the page itself
					if (!$this->cacheFile->isExists() || doubleval($this->cacheFile->getSize()) !== doubleval($written))
					{
						$written = false;
					}
				}
				else
				{
					$written = false;
					$tempFile->delete();
				}
			}
			catch (\Exception $exception)
			{
				$written = false;
				$tempFile->delete();
			}
		}

		return $written;
	}

	public function read()
	{
		if ($this->exists())
		{
			try
			{
				return $this->cacheFile->getContents();
			}
			catch (\Exception $exception)
			{

			}
		}

		return false;
	}

	public function exists()
	{
		if ($this->cacheFile)
		{
			return $this->cacheFile->isExists();
		}
		else
		{
			return false;
		}
	}

	/**
	 * Deletes the cache
	 * @return int|false the number of bytes whose removal is confirmed, false on failure
	 */
	public function delete()
	{
		if (!$this->cacheFile || !$this->cacheFile->isExists())
		{
			return false;
		}

		try
		{
			$cacheDirectory = $this->cacheFile->getDirectory();
			$fileSize = $this->cacheFile->getSize();
			if (!$this->cacheFile->delete())
			{
				return false;
			}
		}
		catch (\Exception $exception)
		{
			return false;
		}

		$this->deleteEmptyDirectory($cacheDirectory);

		return $fileSize;
	}

	/**
	 * Directories are not counted in the quota, so the result is not reported
	 * @param Main\IO\Directory $directory
	 * @return void
	 */
	private function deleteEmptyDirectory(Main\IO\Directory $directory): void
	{
		try
		{
			if (empty($directory->getChildren()))
			{
				$directory->delete();
			}
		}
		catch (\Exception $exception)
		{

		}
	}

	/**
	 * Deletes the files of all pages, the records are left to the caller: it empties the table in one query
	 * @return bool
	 */
	public function deleteAll()
	{
		return (bool)self::deleteFilesRecursive("/", 0, null);
	}

	public function getMd5()
	{
		if ($this->exists())
		{
			$content = $this->read();
			return $content !== false ? Helper::extractContentChecksum($content) : false;
		}

		return false;
	}

	/**
	 * Should we count a quota limit
	 * @return bool
	 */
	public function shouldCountQuota()
	{
		return true;
	}

	public function getLastModified()
	{
		if ($this->exists())
		{
			try
			{
				return $this->cacheFile->getModificationTime();
			}
			catch (\Exception $exception)
			{

			}
		}

		return false;
	}

	/**
	 * Returns cache size
	 * @return int|false
	 */
	public function getSize()
	{
		if ($this->cacheFile && $this->cacheFile->isExists())
		{
			try
			{
				return $this->cacheFile->getSize();
			}
			catch (\Exception $exception)
			{

			}
		}

		return false;
	}

	public function getCacheFile()
	{
		return $this->cacheFile;
	}

	/**
	 * Deletes all above html_pages and removes the records of the deleted pages
	 * @param string $relativePath [optional]
	 * @param int $validTime [optional] unix timestamp
	 * @return float the number of bytes freed on the disk: the counter of the composite gets
	 * the bytes of the pages alone, the disk quota of the portal gets every deleted file
	 */
	public static function deleteRecursive($relativePath = "", $validTime = 0)
	{
		$batch = new PageDeleteBatch();
		try
		{
			$bytes = self::deleteFilesRecursive((string)$relativePath, (int)$validTime, $batch);
		}
		finally
		{
			// the files are gone already: a break of the walk must not lose the keys and their bytes
			$batch->flush();
		}

		return $bytes;
	}

	/**
	 * Deletes a file of the cache folder, keeping the service files.
	 * The folder is the boundary of the method: a path outside it belongs to another cleanup,
	 * and deleting whatever it is given would make an arbitrary path of a caller a deletion
	 * @param string $absolutePath
	 * @param PageDeleteBatch|null $batch accumulator of the deleted pages, null leaves the records alone
	 * @return float|false|null the bytes freed on the disk, false if the file is still on the disk,
	 * null for a file the cleanup does not touch: a service file or a path outside the cache folder
	 */
	private static function deleteFile(string $absolutePath, ?PageDeleteBatch $batch = null): float|false|null
	{
		$relativePath = self::getRelativePath($absolutePath);
		if ($relativePath === null || self::isServicePath($relativePath))
		{
			return null;
		}

		$cacheKey = self::getCacheKeyByRelativePath($relativePath);
		$diskBytes = doubleval(filesize($absolutePath));

		if (!@unlink($absolutePath))
		{
			return false;
		}

		// the counter never got the bytes of a file that is not a page, so they are not its to free
		if ($batch !== null && $cacheKey !== null)
		{
			$batch->add($cacheKey, $diskBytes);
		}

		return $diskBytes;
	}

	/**
	 * Returns the cache key of the page stored in the file
	 * or null if the file is not a page of the composite cache
	 * @param string $absolutePath
	 * @return string|null
	 */
	public static function getCacheKeyByPath(string $absolutePath): ?string
	{
		return self::getCacheKeyByRelativePath(self::getRelativePath($absolutePath));
	}

	/**
	 * @param string|null $relativePath the path relative to the cache folder, null for a file outside it
	 * @return string|null
	 */
	private static function getCacheKeyByRelativePath(?string $relativePath): ?string
	{
		// a page key always ends with .html, anything else under the folder is a stray file
		return $relativePath !== null && str_ends_with($relativePath, ".html") ? $relativePath : null;
	}

	/**
	 * Tells whether the file is a service file: the cleanup keeps them for the web server
	 * @param string $absolutePath
	 * @return bool
	 */
	public static function isServiceFile(string $absolutePath): bool
	{
		return self::isServicePath(self::getRelativePath($absolutePath));
	}

	/**
	 * @param string|null $relativePath the path relative to the cache folder, null for a file outside it
	 * @return bool
	 */
	private static function isServicePath(?string $relativePath): bool
	{
		return $relativePath !== null && in_array($relativePath, self::SERVICE_FILES, true);
	}

	/**
	 * Returns the path of the file relative to the cache folder or null if the file is outside it.
	 * The path comes from the file system, and the key of a page is a logical path, the one the
	 * io layer converts to the physical name on the disk, so the conversion is undone here.
	 * Both sides are normalized: a document root with a trailing slash would leave the prefix matching nothing
	 * @param string $absolutePath
	 * @return string|null
	 */
	private static function getRelativePath(string $absolutePath): ?string
	{
		try
		{
			$path = Main\IO\Path::normalize(Main\IO\Path::convertPhysicalToLogical($absolutePath));
		}
		catch (Main\IO\InvalidPathException $exception)
		{
			return null;
		}

		$baseDir = self::getBaseDirectory();
		if ($path === null || !str_starts_with($path, $baseDir."/"))
		{
			return null;
		}

		return substr($path, strlen($baseDir));
	}

	/**
	 * @param string $relativePath
	 * @param int $validTime unix timestamp
	 * @param PageDeleteBatch|null $batch accumulator of the deleted pages,
	 * null deletes the files without touching the records
	 * @return float the number of bytes whose removal is confirmed
	 */
	private static function deleteFilesRecursive(string $relativePath, int $validTime, ?PageDeleteBatch $batch): float
	{
		$bytes = 0.0;
		if (str_contains($relativePath, ".."))
		{
			return $bytes;
		}

		$relativePath = rtrim($relativePath, "/");
		$baseDir = self::getBaseDirectory();
		$absPath = $baseDir.$relativePath;

		if (is_file($absPath))
		{
			if ($validTime && filemtime($absPath) > $validTime)
			{
				return $bytes;
			}

			$deletedBytes = self::deleteFile($absPath, $batch);

			return is_float($deletedBytes) ? $deletedBytes : 0.0;
		}
		elseif (is_dir($absPath) && ($handle = opendir($absPath)) !== false)
		{
			while (($file = readdir($handle)) !== false)
			{
				if ($file === "." || $file === "..")
				{
					continue;
				}

				$bytes += self::deleteFilesRecursive($relativePath."/".$file, $validTime, $batch);
			}
			closedir($handle);

			if ($baseDir !== $absPath)
			{
				@rmdir($absPath);
			}
		}

		return $bytes;
	}

	/**
	 * Adds the key of the deleted file to the batch, if the file is a page of the composite cache
	 * @param string $absolutePath
	 * @param PageDeleteBatch $batch
	 * @param float $bytes the size of the deleted file
	 * @return bool whether the file is a page of the composite cache
	 */
	public static function collectDeletedPage(string $absolutePath, PageDeleteBatch $batch, float $bytes = 0.0): bool
	{
		$cacheKey = self::getCacheKeyByPath($absolutePath);
		if ($cacheKey === null)
		{
			return false;
		}

		$batch->add($cacheKey, $bytes);

		return true;
	}

	private static function getBaseDirectory(): string
	{
		$documentRoot = (string)($_SERVER["DOCUMENT_ROOT"] ?? "");

		return self::$baseDirectories[$documentRoot] ??= Main\IO\Path::convertRelativeToAbsolute(
			Main\Application::getPersonalRoot()."/html_pages"
		);
	}
}

class_alias("Bitrix\\Main\\Composite\\Data\\FileStorage", "Bitrix\\Main\\Data\\StaticHtmlFileStorage");
