<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2026 Bitrix
 */

namespace Bitrix\Main\UI\Uploader;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Localization\Loc;

class Storage implements Storable
{
	protected $path = "";
	protected static $lastId = null;
	protected static $descriptor = null;

	private static function flush()
	{
		if (!is_null(self::$descriptor))
		{
			@fflush(self::$descriptor);
			@flock(self::$descriptor, LOCK_UN);
			@fclose(self::$descriptor);
			self::$descriptor = null;
		}
	}

	public function __destruct()
	{
		self::flush();
	}

	/**
	 * @param string $path
	 * @param array $file
	 * @return Result
	 */
	public function copy($path, array $file)
	{
		$result = new Result();
		$directory = \CBXVirtualIo::GetInstance()->getDirectory($path);

		$newFile = $directory->GetPathWithName() . "/" . $file["code"];
		$result->setData([
			"size" => $file["size"],
			"tmp_name" => $newFile,
			"type" => $file["type"],
		]);
		if (mb_substr($newFile, -mb_strlen($file['tmp_name'])) == $file['tmp_name'])
		{
		}
		elseif (!$directory->create())
		{
			$result->addError(new Error(Loc::getMessage("BXU_TemporaryDirectoryIsNotCreated"), "BXU347.1"));
		}
		elseif (array_key_exists('tmp_url', $file))
		{
			if (!((!file_exists($newFile) || @unlink($newFile)) && File::http()->download($file['tmp_url'], $newFile) !== false))
			{
				$result->addError(new Error(Loc::getMessage("BXU_FileIsNotUploaded"), "BXU347.2.1"));
			}
		}
		elseif (!file_exists($file['tmp_name']))
		{
			$result->addError(new Error(Loc::getMessage("BXU_FileIsNotUploaded"), "BXU347.2.2"));
		}
		elseif (array_key_exists('start', $file))
		{
			$result = $this->copyChunk($newFile, $file);
		}
		elseif (!((!file_exists($newFile) || @unlink($newFile)) && move_uploaded_file($file['tmp_name'], $newFile)))
		{
			$result->addError(new Error(Loc::getMessage("BXU_FileIsNotUploaded"), "BXU347.2.4"));
		}
		else
		{
			$result->setData([
				"size" => filesize($newFile),
				"tmp_name" => $newFile,
				"type" => ($file["type"] ?: \CFile::GetContentType($newFile)),
			]);
		}
		return $result;
	}

	/**
	 * @param string $path
	 * @param array $chunk
	 * @return Result
	 */
	public function copyChunk($path, array $chunk)
	{
		$result = new Result();
		if (is_null(self::$descriptor) || self::$lastId != $path)
		{
			self::flush();
			self::$descriptor = $fdst = fopen($path, 'cb');
			@chmod($path, BX_FILE_PERMISSIONS);
		}
		else
		{
			$fdst = self::$descriptor;
		}

		if (!$fdst)
		{
			$result->addError(new Error(Loc::getMessage("BXU_TemporaryFileIsNotCreated"), "BXU349.1"));
		}
		else
		{
			if (!flock($fdst, LOCK_EX))
			{
				$result->addError(new Error(Loc::getMessage("BXU_FileIsLocked"), "BXU349.100"));
			}
			else
			{
				$buff = 4096;
				if (($fsrc = fopen($chunk['tmp_name'], 'r')))
				{
					fseek($fdst, $chunk["start"]);
					while (!feof($fsrc) && ($data = fread($fsrc, $buff)))
					{
						if ($data !== '')
						{
							fwrite($fdst, $data);
						}
						else
						{
							$result->addError(new Error(Loc::getMessage("BXU_FilePartCanNotBeRead"), "BXU349.2"));
							break;
						}
					}
					fclose($fsrc);
					unlink($chunk['tmp_name']);
				}
				else
				{
					$result->addError(new Error(Loc::getMessage("BXU_FilePartCanNotBeOpened"), "BXU349.3"));
				}
			}
		}
		if (!$result->isSuccess())
		{
			self::flush();
		}

		$result->setData([
			"size" => $chunk["~size"],
			"tmp_name" => $path,
			"type" => $chunk["type"],
		]);

		return $result;
	}

	public function flushDescriptor()
	{
		self::flush();
	}
}
