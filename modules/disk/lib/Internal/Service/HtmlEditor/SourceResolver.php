<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\HtmlEditor;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\File;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class SourceResolver
{
	public const ERROR_ACCESS_DENIED = 'access_denied';
	public const ERROR_HISTORICAL_VERSION = 'historical_version_not_editable';

	public function resolve(
		File $file,
		int $userId,
		?int $attachedObjectId = null,
		bool $shouldCheckUnifiedLink = false,
	): Result
	{
		$realFile = $file->getRealObject();
		if (!$realFile instanceof File || $realFile->isDeleted())
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}

		$securityContext = $realFile->getStorage()?->getSecurityContext($userId, $shouldCheckUnifiedLink);
		if ($securityContext === null)
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}

		$attachedObject = null;
		if ($attachedObjectId !== null)
		{
			$attachedObject = AttachedObject::loadById($attachedObjectId, ['OBJECT']);
			if (!$attachedObject || $attachedObject->isSpecificVersion())
			{
				return $this->error(
					$attachedObject?->isSpecificVersion()
						? self::ERROR_HISTORICAL_VERSION
						: self::ERROR_ACCESS_DENIED,
				);
			}
			$attachedFile = $attachedObject->getFile();
			if (
				!$attachedFile instanceof File
				|| (int)$attachedFile->getRealObjectId() !== (int)$realFile->getId()
				|| !($attachedObject->canUpdate($userId) || $realFile->canUpdate($securityContext))
			)
			{
				return $this->error(self::ERROR_ACCESS_DENIED);
			}
		}
		elseif (!$realFile->canUpdate($securityContext))
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}

		return (new Result())->setData([
			'file' => $realFile,
			'securityContext' => $securityContext,
			'attachedObject' => $attachedObject,
		]);
	}

	private function error(string $code): Result
	{
		return (new Result())->addError(new Error($code, $code));
	}
}
