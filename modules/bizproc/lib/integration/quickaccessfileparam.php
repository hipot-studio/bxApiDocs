<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Integration;

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

	public function add(string $uri, mixed $file): string
	{
		if ($this->fileDataParameterService === null)
		{
			return $uri;
		}

		$encryptedFileData = $this
			->fileDataParameterService
			->getEncryptedFileData($file)
		;

		if ($encryptedFileData !== null)
		{
			$newUri = (new Uri($uri))
				->addParams([
					FileDataParameterService::PARAMETER_NAME => $encryptedFileData,
				])
			;

			return (string)$newUri;
		}

		return $uri;
	}
}
