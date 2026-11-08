<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Image;

use Bitrix\Mail\Internals\SignatureImageBindingTable;
use Bitrix\Mail\Internals\SignatureImageTable;
use Bitrix\Mail\Internals\SharedSignatureTable;
use Bitrix\Mail\Service\SharedSignature\SharedSignatureService;

final class SignatureImageResourceProvider
{
	private \Closure $loadBindings;
	private \Closure $loadImages;
	private \Closure $loadImageByUuid;
	private \Closure $loadBindingsByImageId;
	private \Closure $loadSignatures;
	private \Closure $loadAssignedSharedSignatureIds;
	private \Closure $canManageSharedSignatures;

	public function __construct(
		?\Closure $loadBindings = null,
		?\Closure $loadImages = null,
		?\Closure $loadImageByUuid = null,
		?\Closure $loadBindingsByImageId = null,
		?\Closure $loadSignatures = null,
		?\Closure $loadAssignedSharedSignatureIds = null,
		?\Closure $canManageSharedSignatures = null,
	)
	{
		$this->loadBindings = $loadBindings ?? static function (array $signatureIds): array {
			return SignatureImageBindingTable::query()
				->setSelect(['SIGNATURE_ID', 'IMAGE_ID'])
				->whereIn('SIGNATURE_ID', $signatureIds)
				->exec()
				->fetchAll()
			;
		};
		$this->loadImages = $loadImages ?? static function (array $imageIds): array {
			return SignatureImageTable::query()
				->setSelect(['ID', 'UUID', 'WIDTH'])
				->whereIn('ID', $imageIds)
				->exec()
				->fetchAll()
			;
		};
		$this->loadImageByUuid = $loadImageByUuid ?? static function (string $uuid): ?array {
			$row = SignatureImageTable::query()
				->setSelect(['ID', 'UUID', 'WIDTH', 'FILE_ID', 'CONTENT_TYPE', 'FILE_SIZE', 'OWNER_ID', 'STATE'])
				->where('UUID', $uuid)
				->setLimit(1)
				->fetch()
			;

			return $row === false ? null : $row;
		};
		$this->loadBindingsByImageId = $loadBindingsByImageId ?? static function (int $imageId): array {
			return SignatureImageBindingTable::query()
				->setSelect(['SIGNATURE_ID'])
				->where('IMAGE_ID', $imageId)
				->exec()
				->fetchAll()
			;
		};
		$this->loadSignatures = $loadSignatures ?? static function (array $signatureIds): array {
			return SharedSignatureTable::query()
				->setSelect(['ID', 'OWNER_ID', 'SCOPE'])
				->whereIn('ID', $signatureIds)
				->exec()
				->fetchAll()
			;
		};
		$this->loadAssignedSharedSignatureIds = $loadAssignedSharedSignatureIds
			?? static fn(int $userId): array => (new SharedSignatureService())
				->getSharedSignatureIdsAssignedToUser($userId)
		;
		$this->canManageSharedSignatures = $canManageSharedSignatures
			?? static fn(): bool => SharedSignatureService::canManageSharedScope();
	}

	/**
	 * @param int[] $signatureIds
	 * @return array<int, array<string, array{WIDTH: int}>> Resources grouped by signature ID and UUID.
	 */
	public function getBySignatureIds(array $signatureIds): array
	{
		$signatureIds = $this->normalizeIds($signatureIds);
		if ($signatureIds === [])
		{
			return [];
		}

		$bindings = ($this->loadBindings)($signatureIds);
		$imageIds = $this->normalizeIds(array_column($bindings, 'IMAGE_ID'));
		if ($imageIds === [])
		{
			return [];
		}

		$imagesById = [];
		foreach (($this->loadImages)($imageIds) as $image)
		{
			$imageId = (int)($image['ID'] ?? 0);
			$uuid = (string)($image['UUID'] ?? '');
			$width = (int)($image['WIDTH'] ?? 0);
			if ($imageId > 0 && $uuid !== '' && $width > 0)
			{
				$imagesById[$imageId] = [
					'UUID' => $uuid,
					'WIDTH' => $width,
				];
			}
		}

		$resources = [];
		foreach ($bindings as $binding)
		{
			$signatureId = (int)($binding['SIGNATURE_ID'] ?? 0);
			$image = $imagesById[(int)($binding['IMAGE_ID'] ?? 0)] ?? null;
			if ($signatureId > 0 && $image !== null)
			{
				$resources[$signatureId][$image['UUID']] = ['WIDTH' => $image['WIDTH']];
			}
		}

		return $resources;
	}

	/**
	 * Returns file metadata only when the UUID is bound to a signature visible through its own scope.
	 * Ownership of the image itself never grants access to a shared signature.
	 *
	 * @return array{ID: int, UUID: string, WIDTH: int, FILE_ID: int, CONTENT_TYPE: string, FILE_SIZE: int}|null
	 */
	public function getAccessibleByUuid(string $uuid, int $userId): ?array
	{
		if ($uuid === '' || $userId <= 0)
		{
			return null;
		}

		$image = ($this->loadImageByUuid)($uuid);
		$imageId = (int)($image['ID'] ?? 0);
		if (!is_array($image) || $imageId <= 0 || (string)($image['UUID'] ?? '') !== $uuid)
		{
			return null;
		}
		if (
			(string)($image['STATE'] ?? '') === SignatureImageTable::STATE_PENDING
			&& (int)($image['OWNER_ID'] ?? 0) === $userId
		)
		{
			return $image;
		}

		$signatureIds = $this->normalizeIds(array_column(
			($this->loadBindingsByImageId)($imageId),
			'SIGNATURE_ID',
		));
		if ($signatureIds === [])
		{
			return null;
		}

		$boundSharedIds = [];
		foreach (($this->loadSignatures)($signatureIds) as $signature)
		{
			$signatureId = (int)($signature['ID'] ?? 0);
			$scope = (string)($signature['SCOPE'] ?? '');
			if (
				$scope === SharedSignatureTable::SCOPE_OWNER
				&& (int)($signature['OWNER_ID'] ?? 0) === $userId
			)
			{
				return $image;
			}
			if ($scope === SharedSignatureTable::SCOPE_SHARED && $signatureId > 0)
			{
				$boundSharedIds[$signatureId] = true;
			}
		}

		if ($boundSharedIds === [])
		{
			return null;
		}
		if (($this->canManageSharedSignatures)())
		{
			return $image;
		}

		$assignedIds = array_fill_keys(
			$this->normalizeIds(($this->loadAssignedSharedSignatureIds)($userId)),
			true,
		);

		return array_intersect_key($boundSharedIds, $assignedIds) === [] ? null : $image;
	}

	/** @param mixed[] $ids */
	private function normalizeIds(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		)));
		sort($ids, SORT_NUMERIC);

		return $ids;
	}
}
