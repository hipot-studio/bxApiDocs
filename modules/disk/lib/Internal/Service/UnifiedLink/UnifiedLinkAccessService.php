<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Internal\Access\UnifiedLink\AccessCheckHandlerFactory;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessResult;
use Bitrix\Disk\Version;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Security\Sign\Signer;

readonly class UnifiedLinkAccessService
{
	protected const TRASH_ULS_SUFFIX = 'T';

	public function __construct(
		private AccessCheckHandlerFactory $accessCheckHandlerFactory,
		private Signer $signer,
		private UnifiedLinkSignature $unifiedLinkSignature = new UnifiedLinkSignature(),
	)
	{
	}

	public function check(
		BaseObject $baseObject,
		?AttachedObject $attachedObject = null,
		int $userId = 0,
		?ExternalLink $externalLink = null,
		?Version $version = null,
	): UnifiedLinkAccessLevel
	{
		return $this->accessCheckHandlerFactory->create(
			attachedObject: $attachedObject,
			userId: $userId,
			externalLink: $externalLink,
			version: $version,
		)->check($baseObject);
	}

	/**
	 * The access given by the unified-link chain alone: the general rights, the attachment and the public
	 * link take no part in it.
	 */
	public function checkUnifiedLinkSource(BaseObject $baseObject, int $userId): UnifiedLinkAccessLevel
	{
		return $this->accessCheckHandlerFactory->getForUnifiedLink($userId)->check($baseObject);
	}

	public function checkAll(
		BaseObject $baseObject,
		?AttachedObject $attachedObject = null,
		int $userId = 0,
	): UnifiedLinkAccessResult
	{
		return $this->accessCheckHandlerFactory->create($attachedObject, $userId)->checkAll($baseObject);
	}

	/**
	 * @param array<int, BaseObject> $baseObjects
	 * @param array<int, array<string, string>>|null $operationsByObjectId
	 * @return array<int, UnifiedLinkAccessResult>
	 */
	public function checkAllCollection(
		array $baseObjects,
		?AttachedObject $attachedObject = null,
		int $userId = 0,
		?array $operationsByObjectId = null,
	): array
	{
		if (empty($baseObjects))
		{
			return [];
		}

		return $this->accessCheckHandlerFactory
			->create(
				attachedObject: $attachedObject,
				userId: $userId,
				operationsByObjectId: $operationsByObjectId,
			)
			->checkAllCollection($baseObjects)
		;
	}

	/**
	 * @param array<int, BaseObject> $baseObjects
	 * @param AttachedObject|null $attachedObject
	 * @param int $userId
	 * @param array<int, array<string, string>> $operationsByObjectId
	 * @return array<int, UnifiedLinkAccessLevel>
	 */
	public function checkCollection(
		array $baseObjects,
		?AttachedObject $attachedObject = null,
		int $userId = 0,
		?array $operationsByObjectId = null,
	): array
	{
		if (empty($baseObjects))
		{
			return [];
		}

		return $this->accessCheckHandlerFactory
			->create(
				attachedObject: $attachedObject,
				userId: $userId,
				operationsByObjectId: $operationsByObjectId,
			)
			->checkCollection($baseObjects)
		;
	}

	/**
	 * @param mixed $objectId
	 * @return string
	 * @throws ArgumentTypeException
	 */
	public function getUlsForObjectId(mixed $objectId): string
	{
		return $this->unifiedLinkSignature->getUlsForObjectId((int)$objectId);
	}

	public function getUlsForVersion(mixed $objectId, mixed $versionId): string
	{
		return $this->unifiedLinkSignature->getUlsForVersion((int)$objectId, (int)$versionId);
	}

	/**
	 * @param mixed $objectId
	 * @return string
	 * @throws ArgumentTypeException
	 */
	public function getTrashUlsForObjectId(mixed $objectId): string
	{
		return $this->signer->getSignature($this->getTrashUlsPayload($objectId));
	}

	/**
	 * @param mixed $objectId
	 * @param string $uls
	 * @return bool
	 */
	public function validateUlsForObjectId(mixed $objectId, string $uls): bool
	{
		return $this->unifiedLinkSignature->validateUlsForObjectId((int)$objectId, $uls);
	}

	public function validateUlsForVersion(mixed $objectId, mixed $versionId, string $uls): bool
	{
		return $this->unifiedLinkSignature->validateUlsForVersion((int)$objectId, (int)$versionId, $uls);
	}

	/**
	 * @param mixed $objectId
	 * @param string $uls
	 * @return bool
	 */
	public function validateTrashUlsForObjectId(mixed $objectId, string $uls): bool
	{
		return $this->signer->validate($this->getTrashUlsPayload($objectId), $uls);
	}

	/**
	 * @param mixed $objectId
	 * @return string
	 */
	protected function getTrashUlsPayload(mixed $objectId): string
	{
		return (string)$objectId . self::TRASH_ULS_SUFFIX;
	}
}
