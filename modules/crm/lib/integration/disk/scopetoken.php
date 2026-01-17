<?php

namespace Bitrix\Crm\Integration\Disk;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\QuickAccess\ScopeTokenService;
use Bitrix\Main\DI\ServiceLocator;

class ScopeToken
{
	private const SCOPE_PREFIX = 'crm';

	private string $scope;
	private ?ScopeTokenService $service;

	public function __construct(string $scope)
	{
		$this->scope = mb_strtoupper(self::SCOPE_PREFIX . '_' . $scope);

		$this->service = ServiceLocator::getInstance()->get('disk.scopeTokenService');
		if (isset($this->service))
		{
			$this->service->grantAccessToScope($this->scope);
		}
	}

	public function getTokenParamName(): string
	{
		return '_esd';
	}

	public function getTokenForFile(AttachedObject|BaseObject $file): ?string
	{
		return $this->service?->getEncryptedScopeForObject($file, $this->scope);
	}

	public function getUrlParamsForFile(AttachedObject|BaseObject $file): ?array
	{
		$token = $this->getTokenForFile($file);
		if (!isset($token))
		{
			return null;
		}

		return [
			$this->getTokenParamName() => $token,
		];
	}

	public function getTokenForFileId(int $file): ?string
	{
		return $this->service?->getEncryptedScopeForObject($file, $this->scope);
	}
}
