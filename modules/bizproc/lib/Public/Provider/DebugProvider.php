<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Internal\Entity\Debugger\Debug;
use Bitrix\Bizproc\Internal\Service\Debugger\DebugService;
use Bitrix\Bizproc\Internal\Service\Debugger\DebugServiceInterface;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class DebugProvider
{
	private readonly DebugServiceInterface $service;

	public function __construct()
	{
		$this->service = new DebugService();
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function getDebug(int $userId, int $templateId, ?array $documentId = null): ?Debug
	{
		return $this->service->getDebug($userId, $templateId, $documentId);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function isEnabled(int $userId, int $templateId, ?array $documentId = null): bool
	{
		return $this->service->isEnabled($userId, $templateId, $documentId);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function getActiveDebugForUser(int $userId): ?Debug
	{
		return $this->service->getActiveDebugForUser($userId);
	}
}
