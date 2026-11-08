<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Image;

use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Internals\SharedSignatureTable;
use Bitrix\Mail\Internals\SignatureImageBindingTable;
use Bitrix\Mail\Internals\SignatureImageTable;
use Bitrix\Mail\Service\SharedSignature\SharedSignatureService;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class SignatureImageMimeResolver
{
	public const ERROR_INVALID_ARGUMENT = 'SIGNATURE_IMAGE_MIME_INVALID_ARGUMENT';
	public const ERROR_RESOLVE_FAILED = 'SIGNATURE_IMAGE_MIME_RESOLVE_FAILED';
	public const ERROR_MAIL_SIZE_EXCEEDED = 'SIGNATURE_IMAGE_MAIL_SIZE_EXCEEDED';

	private const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
	private const MARKER_PATTERN
		= '~(?<prefix><img src=")'
		. '/bitrix/services/main/ajax\.php\?action=mail\.api\.signatureimage\.view&amp;id='
		. '(?<uuid>' . self::UUID_PATTERN . ')'
		. '(?<suffix>" data-bx-mail-signature-image="\k<uuid>"'
		. ' data-bx-mail-signature-image-size="(?:small|medium|original)"'
		. '(?: width="[1-9][0-9]{0,3}")?>)~';
	private const CONTENT_TYPE_EXTENSIONS = [
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/gif' => 'gif',
	];

	private \Closure $loadImages;
	private \Closure $loadBindings;
	private \Closure $loadSignatures;
	private \Closure $loadAssignedSharedSignatureIds;
	private \Closure $makeFileArray;
	private \Closure $getMaxMailSize;

	public function __construct(
		?\Closure $loadImages = null,
		?\Closure $loadBindings = null,
		?\Closure $loadSignatures = null,
		?\Closure $loadAssignedSharedSignatureIds = null,
		?\Closure $makeFileArray = null,
		?\Closure $getMaxMailSize = null,
	)
	{
		$this->loadImages = $loadImages ?? static function (array $uuids): array {
			return SignatureImageTable::query()
				->setSelect(['ID', 'UUID', 'FILE_ID', 'CONTENT_TYPE', 'FILE_SIZE', 'STATE'])
				->whereIn('UUID', $uuids)
				->exec()
				->fetchAll()
			;
		};
		$this->loadBindings = $loadBindings ?? static function (array $imageIds): array {
			return SignatureImageBindingTable::query()
				->setSelect(['SIGNATURE_ID', 'IMAGE_ID'])
				->whereIn('IMAGE_ID', $imageIds)
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
		$this->makeFileArray = $makeFileArray ?? static function (int $fileId): ?array {
			$file = \CFile::makeFileArray($fileId);

			return is_array($file) ? $file : null;
		};
		$this->getMaxMailSize = $getMaxMailSize ?? static fn(): int => (int)Message::getMaxAttachedFilesSize();
	}

	/**
	 * Resolves canonical signature image markers into MIME attachment descriptors.
	 *
	 * @return Result Data keys: html, attachments and totalSize.
	 */
	public function resolve(string $html, int $userId, string $hostname, int $totalSize = 0): Result
	{
		if ($userId <= 0 || $totalSize < 0)
		{
			return $this->failure(self::ERROR_INVALID_ARGUMENT);
		}

		preg_match_all(self::MARKER_PATTERN, $html, $matches);
		$uuids = array_values(array_unique($matches['uuid'] ?? []));
		if ($uuids === [])
		{
			return $this->success($html, [], $totalSize);
		}
		if (count($uuids) > SignatureImageValidator::MAX_SIGNATURE_FILE_COUNT)
		{
			return $this->failure(SignatureImageValidator::ERROR_COUNT_LIMIT_EXCEEDED);
		}

		try
		{
			$imagesByUuid = $this->loadAttachedImages($uuids);
			$accessibleImageIds = $this->loadAccessibleImageIds($imagesByUuid, $userId);
			$resources = $this->buildResources($imagesByUuid, $accessibleImageIds, $hostname);
		}
		catch (\Throwable)
		{
			return $this->failure(self::ERROR_RESOLVE_FAILED);
		}

		$attachments = [];
		foreach ($resources as $resource)
		{
			$attachments[] = $resource['attachment'];
			$totalSize += $resource['size'];
		}

		try
		{
			$maxMailSize = (int)($this->getMaxMailSize)();
		}
		catch (\Throwable)
		{
			return $this->failure(self::ERROR_RESOLVE_FAILED);
		}
		if ($maxMailSize > 0 && $maxMailSize <= ceil($totalSize / 3) * 4)
		{
			return $this->failure(self::ERROR_MAIL_SIZE_EXCEEDED);
		}

		$resolvedHtml = preg_replace_callback(
			self::MARKER_PATTERN,
			static function (array $match) use ($resources): string {
				$resource = $resources[$match['uuid']] ?? null;
				if ($resource === null)
				{
					return '';
				}

				return $match['prefix'] . 'cid:' . $resource['contentId'] . $match['suffix'];
			},
			$html,
		);
		if (!is_string($resolvedHtml))
		{
			return $this->failure(self::ERROR_RESOLVE_FAILED);
		}

		return $this->success($resolvedHtml, $attachments, $totalSize);
	}

	/**
	 * @param string[] $uuids
	 * @return array<string, array<string, mixed>>
	 */
	private function loadAttachedImages(array $uuids): array
	{
		$images = [];
		foreach (($this->loadImages)($uuids) as $image)
		{
			$uuid = (string)($image['UUID'] ?? '');
			$contentType = (string)($image['CONTENT_TYPE'] ?? '');
			if (
				!in_array($uuid, $uuids, true)
				|| (int)($image['ID'] ?? 0) <= 0
				|| (int)($image['FILE_ID'] ?? 0) <= 0
				|| (int)($image['FILE_SIZE'] ?? 0) <= 0
				|| (string)($image['STATE'] ?? '') !== SignatureImageTable::STATE_ATTACHED
				|| !isset(self::CONTENT_TYPE_EXTENSIONS[$contentType])
			)
			{
				continue;
			}

			$images[$uuid] = $image;
		}

		return $images;
	}

	/**
	 * @param array<string, array<string, mixed>> $imagesByUuid
	 * @return array<int, true>
	 */
	private function loadAccessibleImageIds(array $imagesByUuid, int $userId): array
	{
		$imageIds = $this->normalizeIds(array_column($imagesByUuid, 'ID'));
		if ($imageIds === [])
		{
			return [];
		}

		$bindings = ($this->loadBindings)($imageIds);
		$signatureIds = $this->normalizeIds(array_column($bindings, 'SIGNATURE_ID'));
		if ($signatureIds === [])
		{
			return [];
		}

		$accessibleSignatureIds = [];
		$sharedSignatureIds = [];
		foreach (($this->loadSignatures)($signatureIds) as $signature)
		{
			$signatureId = (int)($signature['ID'] ?? 0);
			$scope = (string)($signature['SCOPE'] ?? '');
			if (!in_array($signatureId, $signatureIds, true))
			{
				continue;
			}
			if (
				$scope === SharedSignatureTable::SCOPE_OWNER
				&& (int)($signature['OWNER_ID'] ?? 0) === $userId
			)
			{
				$accessibleSignatureIds[$signatureId] = true;
			}
			elseif ($scope === SharedSignatureTable::SCOPE_SHARED)
			{
				$sharedSignatureIds[$signatureId] = true;
			}
		}

		if ($sharedSignatureIds !== [])
		{
			$assignedIds = $this->normalizeIds(($this->loadAssignedSharedSignatureIds)($userId));
			$accessibleSignatureIds += array_intersect_key($sharedSignatureIds, array_fill_keys($assignedIds, true));
		}

		$accessibleImageIds = [];
		foreach ($bindings as $binding)
		{
			$imageId = (int)($binding['IMAGE_ID'] ?? 0);
			$signatureId = (int)($binding['SIGNATURE_ID'] ?? 0);
			if (isset($accessibleSignatureIds[$signatureId]) && in_array($imageId, $imageIds, true))
			{
				$accessibleImageIds[$imageId] = true;
			}
		}

		return $accessibleImageIds;
	}

	/**
	 * @param array<string, array<string, mixed>> $imagesByUuid
	 * @param array<int, true> $accessibleImageIds
	 * @return array<string, array{attachment: array<string, mixed>, contentId: string, size: int}>
	 */
	private function buildResources(array $imagesByUuid, array $accessibleImageIds, string $hostname): array
	{
		$resources = [];
		foreach ($imagesByUuid as $uuid => $image)
		{
			if (!isset($accessibleImageIds[(int)$image['ID']]))
			{
				continue;
			}

			$file = ($this->makeFileArray)((int)$image['FILE_ID']);
			$path = is_array($file) ? (string)($file['tmp_name'] ?? '') : '';
			if ($path === '')
			{
				continue;
			}

			$contentType = (string)$image['CONTENT_TYPE'];
			$name = basename((string)($file['name'] ?? ''));
			if ($name === '')
			{
				$name = 'signature-image.' . self::CONTENT_TYPE_EXTENSIONS[$contentType];
			}

			$contentId = sprintf(
				'bxasig.%s@%s.mail',
				hash('sha256', $uuid),
				hash('crc32b', strtolower(trim($hostname))),
			);
			$resources[$uuid] = [
				'attachment' => [
					'ID' => $contentId,
					'NAME' => $name,
					'PATH' => $path,
					'CONTENT_TYPE' => $contentType,
					'RELATED' => true,
				],
				'contentId' => $contentId,
				'size' => (int)$image['FILE_SIZE'],
			];
		}

		return $resources;
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

	private function success(string $html, array $attachments, int $totalSize): Result
	{
		return (new Result())->setData([
			'html' => $html,
			'attachments' => $attachments,
			'totalSize' => $totalSize,
		]);
	}

	private function failure(string $code): Result
	{
		return (new Result())->addError(new Error($code, $code));
	}
}
