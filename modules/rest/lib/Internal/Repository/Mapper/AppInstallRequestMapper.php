<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Mapper;

use Bitrix\Rest\Internal\Entity\Application\AppInstallRequest\AppInstallRequest;
use Bitrix\Rest\Internal\Entity\Application\AppInstallRequest\AppInstallRequestStatus;
use Bitrix\Rest\Internal\Model\AppInstallRequestTable;
use Bitrix\Rest\Internal\Model\EO_AppInstallRequest;

class AppInstallRequestMapper
{
	public function convertFromOrm(EO_AppInstallRequest $ormModel): AppInstallRequest
	{
		$entity = new AppInstallRequest(
			appCode: $ormModel->getAppCode(),
			userId: (int)$ormModel->getUserId(),
			status: $this->convertStatusFromOrm((string)$ormModel->getStatus()),
			appVersion: ($ormModel->getAppVersion() !== null && $ormModel->getAppVersion() !== '')
				? (string)$ormModel->getAppVersion()
				: '1',
			checkHash: $ormModel->getCheckHash() !== null ? (string)$ormModel->getCheckHash() : null,
			installHash: $ormModel->getInstallHash() !== null ? (string)$ormModel->getInstallHash() : null,
			appName: $ormModel->getAppName() !== null ? (string)$ormModel->getAppName() : null,
			appIconUrl: $ormModel->getAppIconUrl() !== null ? (string)$ormModel->getAppIconUrl() : null,
			resolvedById: $ormModel->getResolvedById() !== null ? (int)$ormModel->getResolvedById() : null,
			dateCreate: $ormModel->getDateCreate(),
			dateResolve: $ormModel->getDateResolve(),
		);

		$entity->setId($ormModel->getId());

		return $entity;
	}

	public function convertToOrm(AppInstallRequest $entity): EO_AppInstallRequest
	{
		$ormModel = $entity->getId()
			? EO_AppInstallRequest::wakeUp($entity->getId())
			: AppInstallRequestTable::createObject()
		;

		$ormModel
			->setAppCode($entity->getAppCode())
			->setAppVersion($entity->getAppVersion())
			->setUserId($entity->getUserId())
			->setCheckHash($entity->getCheckHash())
			->setInstallHash($entity->getInstallHash())
			->setAppName($entity->getAppName())
			->setAppIconUrl($entity->getAppIconUrl())
			->setStatus($this->convertStatusToOrm($entity->getStatus()))
			->setResolvedById($entity->getResolvedById())
			->setDateCreate($entity->getDateCreate())
			->setDateResolve($entity->getDateResolve())
		;

		return $ormModel;
	}

	private function convertStatusFromOrm(string $dbValue): AppInstallRequestStatus
	{
		return AppInstallRequestStatus::from($dbValue);
	}

	private function convertStatusToOrm(AppInstallRequestStatus $status): string
	{
		return $status->value;
	}
}
