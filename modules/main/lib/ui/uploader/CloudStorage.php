<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2026 Bitrix
 */

namespace Bitrix\Main\UI\Uploader;

use Bitrix\Main\Loader;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\Application;
use Bitrix\Main\File\Image;

class CloudStorage extends Storage implements Storable
{
	protected $moduleId = "main";

	public function __construct($params)
	{
		if (!Loader::includeModule("clouds"))
		{
			throw new NotImplementedException();
		}
		if (is_array($params))
		{
			$params = array_change_key_case($params, CASE_LOWER);
			$this->moduleId = ($params["moduleid"] ?: $this->moduleId);
		}
	}

	/**
	 * @param $file
	 * @return \CCloudStorageBucket|null
	 */
	private function findBucket($file)
	{
		$bucket = \CCloudStorage::findBucketForFile(['FILE_SIZE' => $file['size'], 'MODULE_ID' => $this->moduleId], $file["name"] ?? '');
		if (!$bucket || !$bucket->init())
		{
			return null;
		}
		return $bucket;
	}

	protected function moveIntoCloud(\CCloudStorageBucket $bucket, $path, $file)
	{
		$result = new Result();
		$absPath = \CTempFile::getAbsoluteRoot();
		$relativePath = $path;
		if (str_starts_with($path, $absPath) && mb_strpos($path, "/bxu/") > 0)
		{
			$relativePath = mb_substr($path, mb_strpos($path, "/bxu/"));
		}
		$subdir = explode("/", trim($relativePath, "/"));
		$filename = array_pop($subdir);
		if (!isset(Application::getInstance()->getSession()["upload_tmp"]))
		{
			Application::getInstance()->getSession()["upload_tmp"] = [];
		}

		if (!isset(Application::getInstance()->getSession()["upload_tmp"][$path]))
		{
			$relativePath = Application::getInstance()->getSession()["upload_tmp"][$path] = \CCloudTempFile::GetDirectoryName($bucket, 12) . $filename;
		}
		else
		{
			$relativePath = Application::getInstance()->getSession()["upload_tmp"][$path];
		}

		$upload = new \CCloudStorageUpload($relativePath);
		$finished = false;
		if (!$upload->isStarted() && !$upload->start($bucket->ID, $file["size"], $file["type"]))
		{
			$result->addError(new Error(Loc::getMessage("BXU_FileTransferIntoTheCloudIsFailed"), "BXU346.2"));
		}
		else
		{
			if (!($fileContent = \Bitrix\Main\IO\File::getFileContents($file["tmp_name"])))
			{
				$result->addError(new Error(Loc::getMessage("BXU_FileIsFailedToRead"), "BXU346.3"));
			}
			else
			{
				$fails = 0;
				$success = false;
				while ($upload->hasRetries())
				{
					if (method_exists($upload, "part") && $upload->part($fileContent, ($file["number"] ?? 0)) ||
						!method_exists($upload, "part") && $upload->next($fileContent))
					{
						$success = true;
						break;
					}
					$fails++;
				}
				if (!$success)
				{
					$result->addError(new Error("Could not upload file for {$fails} times.", "BXU346.4"));
				}
				else
				{
					if (isset($file["count"]) && $upload->GetPartCount() < $file["count"])
					{
					}
					else
					{
						if (!$upload->finish())
						{
							$result->addError(new Error("Could not resume file transfer.", "BXU346.5"));
						}
						else
						{
							$finished = true;
						}
					}
				}
			}
		}

		$result->setData([
			"tmp_name" => $bucket->getFileSRC($relativePath),
			"size" => $file["size"],
			"type" => $file["type"],
			"finished" => $finished,
		]);
		return $result;
	}

	public function copy($path, array $file)
	{
		$result = parent::copy($path, $file);
		if ($result->isSuccess())
		{
			if (!array_key_exists('start', $file))
			{
				$res = $result->getData();
				$file["tmp_name"] = $res["tmp_name"];
				$file["size"] = $res["size"];
				$file["type"] = $res["type"];
				$info = (new Image($file["tmp_name"]))->getInfo();
				if ($info)
				{
					$file["width"] = $info->getWidth();
					$file["height"] = $info->getHeight();
				}
				else
				{
					$file["width"] = 0;
					$file["height"] = 0;
				}
				if ($bucket = $this->findBucket($file))
				{
					unset($file["count"]);
					if (($r = $this->moveIntoCloud($bucket, $file["tmp_name"], $file)) && $r->isSuccess())
					{
						$res = $r->getData();
						$result->setData([
							"size" => $file["size"],
							"file_size" => $file["size"],
							"tmp_name" => $res["tmp_name"],
							"type" => $file["type"],
							"width" => $file["width"],
							"height" => $file["height"],
							"bucketId" => $bucket->ID,
						]);
					}
					if ($r->getErrors())
					{
						$result->addErrors($r->getErrors());
					}
					@unlink($path);
				}
			}
			else
			{
				if ($file["start"] <= 0)
				{
					$res = $result->getData();
					if (($info = (new Image($file["tmp_name"]))->getInfo()))
					{
						$file["width"] = $info->getWidth();
						$file["height"] = $info->getHeight();
						$result->setData(array_merge($res, ["width" => $file["width"], "height" => $file["height"]]));
					}
				}
			}
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
		if ($bucket = $this->findBucket([
			"name" => $chunk["~name"],
			"size" => $chunk["~size"],
		]))
		{
			if (($result = $this->moveIntoCloud($bucket, $path, array_merge($chunk, ["size" => $chunk["~size"]]))) &&
				$result->isSuccess() && ($res = $result->getData()) && $res["finished"] === true)
			{
				$res = $result->getData();
				$result->setData([
					"size" => $chunk["~size"],
					"file_size" => $chunk["~size"],
					"tmp_name" => $res["tmp_name"],
					"type" => $chunk["type"],
					"bucketId" => $bucket->ID,
				]);
			}
		}
		else
		{
			$result = parent::copyChunk($path, $chunk);
		}
		return $result;
	}

	/**
	 * Checks storage.
	 * @param int $id
	 * @return bool
	 */
	public static function checkBucket($id)
	{
		$res = false;
		if (Loader::includeModule("clouds"))
		{
			$r = \CCloudStorageBucket::GetAllBuckets();
			$res = (is_array($r) && array_key_exists($id, $r));
		}
		return $res;
	}
}
