<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Forward;

use Bitrix\Im\V2\Result;
use Bitrix\Main\Error;

final class ForwardResultFormatter
{
	private array $uuidMap;
	private array $failureMap = [];
	private array $legacyFailureMap;
	private bool $hasSuccess;

	/** @var Error[] */
	private array $errors;

	public function __construct(Result $result)
	{
		$uuidMap = $result->getResult();
		$this->uuidMap = is_array($uuidMap) ? $uuidMap : [];
		$this->hasSuccess = $result instanceof ForwardResult
			? $result->hasSuccessfulMessages()
			: !empty($this->uuidMap)
		;
		$this->legacyFailureMap = $result instanceof ForwardResult ? $result->getLegacyFailureMap() : [];
		$this->errors = $result->getErrors();

		foreach ($this->errors as $error)
		{
			$customData = $error->getCustomData();
			$uuid = is_array($customData) ? ($customData['uuid'] ?? null) : null;
			$code = (string)$error->getCode();

			if (is_string($uuid) && $uuid !== '' && $code !== '')
			{
				$this->failureMap[$uuid] = $code;
			}
		}
	}

	public function getUuidMap(): array
	{
		return $this->uuidMap;
	}

	public function getFailureMap(): array
	{
		return $this->failureMap;
	}

	public function getLegacyFailureMap(): array
	{
		return $this->legacyFailureMap;
	}

	public function hasSuccess(): bool
	{
		return $this->hasSuccess;
	}

	/** @return Error[] */
	public function getErrors(): array
	{
		return $this->errors;
	}
}
