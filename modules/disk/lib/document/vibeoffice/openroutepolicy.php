<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\File;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Version;

final class OpenRoutePolicy
{
	private const ADDITIONAL_EXTENSIONS = [
		'csv', 'docm', 'dotm', 'dotx', 'odp', 'otp', 'ots', 'ott',
		'potm', 'potx', 'ppsm', 'ppsx', 'pptm', 'tsv', 'xlsb',
		'xlsm', 'xltm', 'xltx', 'dot', 'pot', 'xlt', 'xodt',
	];

	public static function select(
		File $file,
		?Version $version = null,
		?AttachedObject $attachedObject = null,
	): ?array
	{
		$realFileId = $file->isLink() ? $file->getRealObjectId() : $file->getId();
		if ($attachedObject !== null && (int)$attachedObject->getObjectId() !== (int)$file->getId())
		{
			$attachedFile = $attachedObject->getFile();
			if ($attachedFile === null
				|| (int)$attachedFile->getRealObjectId() !== (int)$realFileId)
			{
				return null;
			}
		}

		if ($version === null && $attachedObject?->isSpecificVersion())
		{
			$version = $attachedObject->getVersion();
			if ($version === null)
			{
				return null;
			}
		}

		if ($version !== null && (int)$version->getObjectId() !== (int)$realFileId)
		{
			return null;
		}

		return [
			'file' => $file,
			'version' => $version,
			'editable' => VibeofficeHandler::isEditable($version?->getExtension() ?? $file->getExtension()),
		];
	}

	public static function selectSession(DocumentSession $session): ?array
	{
		$file = $session->getFile();
		if ($file === null)
		{
			return null;
		}

		$version = $session->getVersionId() > 0 ? $session->getVersion() : null;
		if ($session->getVersionId() > 0 && $version === null)
		{
			return null;
		}

		return self::select($file, $version);
	}

	public static function listAdditionalExtensions(): array
	{
		return self::ADDITIONAL_EXTENSIONS;
	}

	public static function isAdditionalOfficeSource(File $file, ?Version $version = null): bool
	{
		$type = (int)$file->getTypeFile();
		$isDocumentVersion = $type === TypeFile::DOCUMENT && $version !== null;
		$isNormalizedOffice = $type === TypeFile::DOCUMENT
			&& in_array(mb_strtolower($file->getExtension()), ['xlsm', 'odp'], true);
		if (!in_array($type, [TypeFile::KNOWN, TypeFile::UNKNOWN], true) && !$isDocumentVersion && !$isNormalizedOffice)
		{
			return false;
		}

		$extension = mb_strtolower($version?->getExtension() ?? $file->getExtension());

		return in_array($extension, self::ADDITIONAL_EXTENSIONS, true);
	}
}
