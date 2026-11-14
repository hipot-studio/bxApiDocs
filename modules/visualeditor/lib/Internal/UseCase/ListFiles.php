<?php

namespace Bitrix\VisualEditor\Internal\UseCase;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\VisualEditor\Public\Adapter\AdapterRegistry;

final class ListFiles
{
	public function __construct(private readonly AdapterRegistry $registry = new AdapterRegistry())
	{
	}

	public function execute(string $adapterId, array $context): Result
	{
		$adapter = $this->registry->get($adapterId);
		if ($adapter === null)
		{
			Loc::loadMessages(__FILE__);

			return (new Result())->addError(new Error(
				Loc::getMessage('VISUALEDITOR_ADAPTER_NOT_FOUND'),
				'adapter-error',
			));
		}

		$result = $adapter->list($context);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$data = $result->getData();
		$isValid = isset($data['paths'], $data['truncated'])
			&& is_array($data['paths'])
			&& is_bool($data['truncated'])
		;
		if ($isValid)
		{
			foreach ($data['paths'] as $path)
			{
				if (!is_string($path))
				{
					$isValid = false;
					break;
				}
			}
		}

		if (!$isValid)
		{
			Loc::loadMessages(__FILE__);
			$result->addError(new Error(
				Loc::getMessage('VISUALEDITOR_ADAPTER_ERROR'),
				'adapter-error',
			));
		}

		return $result;
	}
}
