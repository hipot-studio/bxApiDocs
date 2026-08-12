<?php
declare(strict_types=1);

namespace Bitrix\Crm\Integration\Disk;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\QuickAccess\FileDataParameterService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;

class QuickAccessFileParam
{
	private ?FileDataParameterService $fileDataParameterService = null;

	public function __construct()
	{
		if (Loader::includeModule('disk') && ServiceLocator::getInstance()->has('disk.fileDataParameterService'))
		{
			$this->fileDataParameterService = ServiceLocator::getInstance()->get('disk.fileDataParameterService');
		}
	}

	public function add(Uri $uri, AttachedObject|BaseObject $file): void
	{
		if ($this->fileDataParameterService === null)
		{
			return;
		}

		$encryptedFileData = $this->fileDataParameterService->getEncryptedFileData($file);

		if ($encryptedFileData !== null)
		{
			$uri
				->addParams([
					FileDataParameterService::PARAMETER_NAME => $encryptedFileData,
				])
			;
		}
	}
}
