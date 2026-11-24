<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Internal;

use Bitrix\Disk\Document\Vibeoffice\SavedSnapshotHasher;
use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Version;
use Bitrix\Main\ORM\Query\Query;

/** @internal */
final class SavedSnapshotProofResolver
{
	public function __construct(private readonly SavedSnapshotHasher $snapshotHasher)
	{
	}

	public function matches(array $receipt, string $sha256): bool
	{
		if ($receipt['CONTENT_SHA256'] !== null)
		{
			return $receipt['CONTENT_SHA256'] === $sha256;
		}
		$key = $receipt['HOST_DOC_KEY'];
		$canonical = DeliveryTable::query()->setSelect(['STAGED_FILE_ID'])
			->where('DELIVERY_ID', (int)$receipt['DELIVERY_ID'])
			->where('SNAPSHOT_SEQ', (int)$receipt['SNAPSHOT_SEQ'])
			->where(Query::filter()->logic('or')->where('HOST_DOC_KEY', $key)
				->where(Query::filter()->whereNull('HOST_DOC_KEY')->where('EXTERNAL_HASH', $key)))
			->where('STAGED_FILE_ID', '>', 0)->setOrder(['ID' => 'ASC'])->fetch()
		;
		if ($canonical)
		{
			$original = $this->snapshotHasher->hash((int)$canonical['STAGED_FILE_ID']);
			if ($original->isSuccess())
			{
				return $original->getData()['sha256'] === $sha256;
			}
		}
		$version = (int)$receipt['RESULT_VERSION_ID'] > 0
			? Version::loadById((int)$receipt['RESULT_VERSION_ID']) : null;
		if ($receipt['RESULT_CONTENT_VERSION'] === null || !$version
			|| (int)$version->getObjectId() !== (int)$receipt['RESULT_OBJECT_ID']
			|| (int)$version->getGlobalContentVersion() !== (int)$receipt['RESULT_CONTENT_VERSION'])
		{
			return false;
		}
		$original = $this->snapshotHasher->hash((int)$version->getFileId());

		return $original->isSuccess() && $original->getData()['sha256'] === $sha256;
	}
}
