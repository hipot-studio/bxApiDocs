<?php

namespace Bitrix\Sign\Operation\Member;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\MimeType;
use Bitrix\Sign\Contract\Operation;
use Bitrix\Sign\File;
use Bitrix\Sign\Item\Fs\File as FsFile;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Sign\Operation\GetSignedFilePdfUrl;

class DownloadResultFile implements Operation
{
	private string $documentUid;
	private string $memberUid;
	private ?FsFile $file = null;

	public function __construct(string $documentUid, string $memberUid)
	{
		$this->documentUid = $documentUid;
		$this->memberUid = $memberUid;
	}

	public function launch(): Result
	{
		// FIXME this operation provides the required link as a fallback, but needs to be reworked to use the API
		$operation = new GetSignedFilePdfUrl($this->documentUid, $this->memberUid);
		$result = $operation->launch();

		if (!$result->isSuccess()) {
			return $result;
		}

		$fileUrl = $operation->url;

		$http = new HttpClient();

		$tmpPath = \CFile::GetTempName('', 'B24Sign_' . $this->memberUid);

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

	public function getFile(): ?FsFile
	{
		return $this->file;
	}

	private function detectExtensionByType(string $mimeType): ?string
	{
		return match ($mimeType)
		{
			'application/zip' => 'zip',
			'application/pdf' => 'pdf',
			default => MimeType::getExtensionByMimeType($mimeType),
		};
	}
}

