<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Image;

use Bitrix\Mail\Internals\SignatureImageBindingTable;
use Bitrix\Mail\Internals\SignatureImageTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

final class SignatureImageBindingService
{
	public const ERROR_RESOLVE_FAILED = 'SIGNATURE_IMAGE_RESOLVE_FAILED';

	private const MARKER_PATTERN
		= '/<img src="\/bitrix\/services\/main\/ajax\.php\?action=mail\.api\.signatureimage\.view&amp;id='
		. '([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})" '
		. 'data-bx-mail-signature-image="\1" '
		. 'data-bx-mail-signature-image-size="(?:small|medium|original)">/';

	private SignatureImageValidator $validator;

	public function __construct(?SignatureImageValidator $validator = null)
	{
		$this->validator = $validator ?? new SignatureImageValidator();
	}

	/**
	 * Resolves canonical markers before the signature transaction starts.
	 */
	public function prepare(string $template, int $userId, ?int $signatureId = null): Result
	{
		$uuids = $this->extractUuids($template);
		$limitResult = $this->validator->validateSignatureLimits(count($uuids), 0);
		if (!$limitResult->isSuccess())
		{
			return $limitResult;
		}

		if ($uuids === [])
		{
			return (new Result())->setData(['imageIds' => []]);
		}

		$imagesByUuid = [];
		$rows = SignatureImageTable::query()
			->setSelect(['ID', 'UUID', 'OWNER_ID', 'FILE_SIZE', 'STATE'])
			->whereIn('UUID', $uuids)
			->exec()
		;
		while ($row = $rows->fetch())
		{
			$imagesByUuid[(string)$row['UUID']] = $row;
		}

		if (count($imagesByUuid) !== count($uuids))
		{
			return $this->failure();
		}

		$boundImageIds = $signatureId !== null && $signatureId > 0
			? array_fill_keys($this->getImageIdsForSignature($signatureId), true)
			: [];
		$imageIds = [];
		$totalSize = 0;
		foreach ($uuids as $uuid)
		{
			$image = $imagesByUuid[$uuid];
			$imageId = (int)$image['ID'];
			if ((string)$image['STATE'] === SignatureImageTable::STATE_DELETING)
			{
				return $this->failure();
			}

			$isAlreadyBound = isset($boundImageIds[$imageId]);
			if (!$isAlreadyBound && (int)$image['OWNER_ID'] !== $userId)
			{
				return $this->failure();
			}
			if (
				(string)$image['STATE'] === SignatureImageTable::STATE_PENDING
				&& (int)$image['OWNER_ID'] !== $userId
			)
			{
				return $this->failure();
			}

			$imageIds[] = $imageId;
			$totalSize += (int)$image['FILE_SIZE'];
		}

		$limitResult = $this->validator->validateSignatureLimits(count($imageIds), $totalSize);
		if (!$limitResult->isSuccess())
		{
			return $limitResult;
		}

		return (new Result())->setData(['imageIds' => $imageIds]);
	}

	/**
	 * Synchronizes bindings inside the transaction owned by SharedSignatureService.
	 *
	 * @param int[] $imageIds
	 */
	public function sync(int $signatureId, array $imageIds): Result
	{
		$imageIds = $this->normalizeIds($imageIds);
		$existingImageIds = $this->getImageIdsForSignature($signatureId);
		$affectedImageIds = $this->normalizeIds([...$existingImageIds, ...$imageIds]);
		$lockedImageIds = $this->lockImages($affectedImageIds);
		if (array_diff($imageIds, $lockedImageIds) !== [])
		{
			return $this->failure();
		}

		$toAdd = array_values(array_diff($imageIds, $existingImageIds));
		$toRemove = array_values(array_diff($existingImageIds, $imageIds));

		foreach ($toAdd as $imageId)
		{
			$addResult = $this->addBinding($signatureId, $imageId);
			if (!$addResult->isSuccess())
			{
				return $addResult;
			}

			$stateResult = SignatureImageTable::update($imageId, [
				'STATE' => SignatureImageTable::STATE_ATTACHED,
				'DATE_MODIFY' => new DateTime(),
			]);
			if (!$stateResult->isSuccess())
			{
				return (new Result())->addErrors($stateResult->getErrors());
			}
		}

		foreach ($toRemove as $imageId)
		{
			$deleteResult = SignatureImageBindingTable::delete([
				'SIGNATURE_ID' => $signatureId,
				'IMAGE_ID' => $imageId,
			]);
			if (!$deleteResult->isSuccess())
			{
				return (new Result())->addErrors($deleteResult->getErrors());
			}

			if (!$this->hasBindingForImage($imageId) && in_array($imageId, $lockedImageIds, true))
			{
				$stateResult = SignatureImageTable::update($imageId, [
					'STATE' => SignatureImageTable::STATE_ORPHANED,
					'DATE_MODIFY' => new DateTime(),
				]);
				if (!$stateResult->isSuccess())
				{
					return (new Result())->addErrors($stateResult->getErrors());
				}
			}
		}

		return new Result();
	}

	/**
	 * Removes the signature side of the relation without deleting file bytes.
	 */
	public function deleteBySignature(int $signatureId): Result
	{
		return $this->sync($signatureId, []);
	}

	/** @return string[] */
	private function extractUuids(string $template): array
	{
		preg_match_all(self::MARKER_PATTERN, $template, $matches);

		return array_values(array_unique($matches[1] ?? []));
	}

	/** @return int[] */
	private function getImageIdsForSignature(int $signatureId): array
	{
		$ids = [];
		$rows = SignatureImageBindingTable::query()
			->setSelect(['IMAGE_ID'])
			->where('SIGNATURE_ID', $signatureId)
			->exec()
		;
		while ($row = $rows->fetch())
		{
			$ids[] = (int)$row['IMAGE_ID'];
		}

		return $this->normalizeIds($ids);
	}

	/**
	 * @param int[] $imageIds
	 * @return int[]
	 */
	private function lockImages(array $imageIds): array
	{
		if ($imageIds === [])
		{
			return [];
		}

		$connection = Application::getConnection();
		$filter = Query::filter()
			->whereIn('ID', $imageIds)
			->whereNot('STATE', SignatureImageTable::STATE_DELETING)
		;
		$rows = $connection->query(sprintf(
			'SELECT %1$s FROM %2$s WHERE %3$s ORDER BY %1$s FOR UPDATE',
			$connection->getSqlHelper()->quote('ID'),
			$connection->getSqlHelper()->quote(SignatureImageTable::getTableName()),
			Query::buildFilterSql(SignatureImageTable::getEntity(), $filter),
		))->fetchAll();

		return $this->normalizeIds(array_column($rows, 'ID'));
	}

	private function hasBindingForImage(int $imageId): bool
	{
		$connection = Application::getConnection();
		$filter = Query::filter()->where('IMAGE_ID', $imageId);

		return (bool)$connection->query(sprintf(
			'SELECT %1$s FROM %2$s WHERE %3$s ORDER BY %1$s FOR UPDATE',
			$connection->getSqlHelper()->quote('SIGNATURE_ID'),
			$connection->getSqlHelper()->quote(SignatureImageBindingTable::getTableName()),
			Query::buildFilterSql(SignatureImageBindingTable::getEntity(), $filter),
		))->fetch();
	}

	private function addBinding(int $signatureId, int $imageId): Result
	{
		try
		{
			$addResult = SignatureImageBindingTable::add([
				'SIGNATURE_ID' => $signatureId,
				'IMAGE_ID' => $imageId,
			]);
		}
		catch (DuplicateEntryException)
		{
			$exists = SignatureImageBindingTable::query()
				->setSelect(['IMAGE_ID'])
				->where('SIGNATURE_ID', $signatureId)
				->where('IMAGE_ID', $imageId)
				->setLimit(1)
				->fetch()
			;

			return $exists === false ? $this->failure() : new Result();
		}

		return $addResult->isSuccess()
			? new Result()
			: (new Result())->addErrors($addResult->getErrors());
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private function normalizeIds(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		sort($ids, SORT_NUMERIC);

		return $ids;
	}

	private function failure(): Result
	{
		return (new Result())->addError(new Error(self::ERROR_RESOLVE_FAILED, self::ERROR_RESOLVE_FAILED));
	}
}
