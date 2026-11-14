<?php

namespace Bitrix\VisualEditor\Internal\UseCase;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\VisualEditor\Public\Adapter\AdapterRegistry;

final class ReadFile
{
	public function __construct(private readonly AdapterRegistry $registry = new AdapterRegistry())
	{
	}

	public function execute(string $adapterId, array $context, string $path): Result
	{
		$adapter = $this->registry->get($adapterId);
		if ($adapter === null)
		{
			Loc::loadMessages(__DIR__ . '/ListFiles.php');

			return (new Result())->addError(new Error(
				Loc::getMessage('VISUALEDITOR_ADAPTER_NOT_FOUND'),
				'adapter-error',
			));
		}

		$result = $adapter->read($context, $path);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$data = $result->getData();
		if (!array_key_exists('content', $data) || ($data['content'] !== null && !is_string($data['content'])))
		{
			Loc::loadMessages(__FILE__);
			$result->addError(new Error(
				Loc::getMessage('VISUALEDITOR_READ_ERROR'),
				'read-error',
			));
		}

		return $result;
	}
}
