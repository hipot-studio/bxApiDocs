<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Rest\Internal\Entity\Access\AccessPermission;
use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;
use Bitrix\Rest\Internal\Contract\Repository\AccessPermissionRepositoryInterface;
use Bitrix\Rest\Internal\Repository\AccessPermissionRepository;
use Bitrix\Rest\Internal\Model\AccessPermissionTable;
use Bitrix\Rest\Internal\Service\Security\SecurityAuditLogger;

class AccessCodesService
{
	public function __construct(
		private AccessPermissionRepositoryInterface $repository = new AccessPermissionRepository(),
		private SecurityAuditLogger $securityAuditLogger = new SecurityAuditLogger(),
	)
	{
	}

	/**
	 * @param string[] $accessCodes
	 */
	public function setAccessCodes(
		EntityType $entityType,
		PermissionType $permission,
		array $accessCodes,
	): void
	{
		if ($entityType === EntityType::Embedding && $permission === PermissionType::ManageOwnEmbeddings)
		{
			$this->setOwnEmbeddingAccessCodes($accessCodes);

			return;
		}

		$previousAccessCodes = $this->repository->deleteByEntityTypeAndPermission($entityType, $permission);

		foreach ($accessCodes as $accessCode)
		{
			$this->repository->save(new AccessPermission($entityType, $accessCode, $permission));
		}

		$this->securityAuditLogger->logAccessPolicyChanged(
			$entityType,
			$permission,
			$previousAccessCodes,
			$accessCodes,
		);
	}

	private function setOwnEmbeddingAccessCodes(array $accessCodes): void
	{
		$normalizedCodes = [];
		foreach ($accessCodes as $accessCode)
		{
			if (!is_string($accessCode) || preg_match('/\p{Cc}/u', $accessCode) !== 0)
			{
				throw new ArgumentException('Invalid access code', 'INVALID_ACCESS_CODES');
			}
			$accessCode = trim($accessCode);
			if ($accessCode === '' || mb_strlen($accessCode) > 100)
			{
				throw new ArgumentException('Invalid access code', 'INVALID_ACCESS_CODES');
			}
			$accessCode = $accessCode === 'UA' ? 'AU' : $accessCode;
			$normalizedCodes[$accessCode] = $accessCode;
		}
		$accessCodes = array_values($normalizedCodes);
		$entityType = EntityType::Embedding;
		$permission = PermissionType::ManageOwnEmbeddings;
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$previousAccessCodes = $this->repository->deleteByEntityTypeAndPermission($entityType, $permission);
			foreach ($accessCodes as $accessCode)
			{
				$this->repository->save(new AccessPermission($entityType, $accessCode, $permission));
			}
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			try
			{
				$connection->rollbackTransaction();
			}
			finally
			{
				AccessPermissionTable::cleanCache();
			}

			throw $exception;
		}
		AccessPermissionTable::cleanCache();

		try
		{
			$this->securityAuditLogger->logAccessPolicyChanged(
				$entityType,
				$permission,
				$previousAccessCodes,
				$accessCodes,
			);
		}
		catch (\Throwable $exception)
		{
			throw new \RuntimeException('POLICY_AUDIT_FAILED', 0, $exception);
		}
	}

	/**
	 * @return string[]
	 */
	public function getAccessCodes(
		EntityType $entityType,
		PermissionType $permission,
	): array
	{
		return $this->repository->getAccessCodesByEntityTypeAndPermission($entityType, $permission);
	}

	/**
	 * @param string[] $userAccessCodes
	 * @return PermissionType[]
	 */
	public function getUserPermissions(EntityType $entityType, array $userAccessCodes): array
	{
		if (empty($userAccessCodes))
		{
			return [];
		}

		$accessPermissions = $this->repository->getByEntityTypeAndAccessCodes($entityType, $userAccessCodes);

		$permissions = [];
		foreach ($accessPermissions as $accessPermission)
		{
			$permissions[$accessPermission->getPermission()->value] = $accessPermission->getPermission();
		}

		return array_values($permissions);
	}
}
