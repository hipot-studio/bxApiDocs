<?php

namespace Bitrix\Sign\Operation\Member;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\MimeType;
use Bitrix\Sign\File;
use Bitrix\Sign\Item\Fs\File as FsFile;
use Bitrix\Sign\Operation\GetPrintVersionPdfUrl;
use Bitrix\Sign\Operation\GetSignedFilePdfUrl;

class DownloadPrintVersionFile extends AbstractDownloadMemberFile
{
	public function launch(): Result
	{
		$operation = $this->getPdfUrlOperation();
		$result = $operation->launch();

		if (!$result->isSuccess())
		{
			return $result;
		}

		$fileUrl = $operation->url;

		$http = new HttpClient();

		$tmpPath = \CFile::GetTempName('', 'B24SignPrint_' . $this->memberUid);

		if (!$http->download($fileUrl, $tmpPath))
		{
			return (new Result())->addError(new Error('Failed to download file'));
		}

		$file = new File($tmpPath);

		$origExt = \Bitrix\Main\IO\Path::getExtension($file->getPath());
		if (!$origExt)
		{
			$mimeType = MimeType::normalize($file->getType());
			$detectedExt = $this->detectExtensionByType($mimeType);
			if ($detectedExt)
			{
				$file->setName(rtrim($file->getName(), '.') . '.' . $detectedExt);
			}
		}

		$this->file = FsFile::createByLegacyFile($file);
		$this->file->dir = '';

		return new Result();
	}

	protected function getPdfUrlOperation(): GetPrintVersionPdfUrl|GetSignedFilePdfUrl
	{
		return new GetPrintVersionPdfUrl($this->documentUid, $this->memberUid);
	}
}

