<?php

namespace Bitrix\Sign\Operation\Member;

use Bitrix\Main\Result;
use Bitrix\Main\Web\MimeType;
use Bitrix\Sign\Contract\Operation;
use Bitrix\Sign\Item\Fs\File as FsFile;
use Bitrix\Sign\Operation\GetPrintVersionPdfUrl;
use Bitrix\Sign\Operation\GetSignedFilePdfUrl;

abstract class AbstractDownloadMemberFile implements Operation
{
	protected string $documentUid;
	protected string $memberUid;
	protected ?FsFile $file = null;

	public function __construct(string $documentUid, string $memberUid)
	{
		$this->documentUid = $documentUid;
		$this->memberUid = $memberUid;
	}

	abstract public function launch(): Result;

	abstract protected function getPdfUrlOperation(): GetPrintVersionPdfUrl|GetSignedFilePdfUrl;

	public function getFile(): ?FsFile
	{
		return $this->file;
	}

	protected function detectExtensionByType(string $mimeType): ?string
	{
		return match ($mimeType)
		{
			'application/zip' => 'zip',
			'application/pdf' => 'pdf',
			default => MimeType::getExtensionByMimeType($mimeType),
		};
	}
}
