<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Vibeoffice\Models\SavedSnapshotTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;

final class SavedSnapshotCompletion
{
	public static function getNotificationLockName(array $delivery): string
	{
		$key = $delivery['HOST_DOC_KEY'] ?? $delivery['EXTERNAL_HASH'];
		$identity = $delivery['SNAPSHOT_SEQ'] === null
			? 'delivery:' . $delivery['ID']
			: 'snapshot:' . $key . ':' . $delivery['SNAPSHOT_SEQ'];

		return 'disk_vo_notify_' . hash('sha256', $identity);
	}

	public function resolve(array $delivery, bool $requireReceipt = false): Result
	{
		if (!$this->isApplied($delivery))
		{
			return $this->failure();
		}
		if ($delivery['SNAPSHOT_SEQ'] === null)
		{
			return $requireReceipt ? $this->failure() : $this->owner($delivery);
		}
		$key = $delivery['HOST_DOC_KEY'] ?? $delivery['EXTERNAL_HASH'];
		$receipt = SavedSnapshotTable::query()->setSelect(['*'])
			->where('HOST_DOC_KEY', $key)->where('SNAPSHOT_SEQ', (int)$delivery['SNAPSHOT_SEQ'])->fetch()
		;
		if (!$receipt)
		{
			return $requireReceipt ? $this->failure() : $this->owner($delivery);
		}
		if (!$this->matchesReceipt($delivery, $receipt))
		{
			return $this->failure();
		}
		$key = $receipt['HOST_DOC_KEY'];
		$rows = $receipt['DELIVERY_ID'] === null ? [] : DeliveryTable::query()->setSelect(['*'])
			->where('DELIVERY_ID', (int)$receipt['DELIVERY_ID'])
			->where(Query::filter()->logic('or')->where('HOST_DOC_KEY', $key)
				->where(Query::filter()->whereNull('HOST_DOC_KEY')->where('EXTERNAL_HASH', $key)))
			->setOrder(['ID' => 'ASC'])->fetchAll()
		;
		if ($rows === [])
		{
			// A purged ledger cannot prove its old notification checkpoint.
			return $this->owner($delivery);
		}
		foreach ($rows as $row)
		{
			if ($this->isApplied($row) && $this->matchesReceipt($row, $receipt)
				&& ($delivery['RESULT_CONTENT_VERSION'] === null || $row['RESULT_CONTENT_VERSION'] === null
					|| (int)$delivery['RESULT_CONTENT_VERSION'] === (int)$row['RESULT_CONTENT_VERSION']))
			{
				return $this->owner($row);
			}
		}

		return $this->failure();
	}

	private function matchesReceipt(array $delivery, array $receipt): bool
	{
		return ($delivery['HOST_DOC_KEY'] === $receipt['HOST_DOC_KEY']
				|| ($delivery['HOST_DOC_KEY'] === null && $delivery['EXTERNAL_HASH'] === $receipt['HOST_DOC_KEY']))
			&& $delivery['SNAPSHOT_SEQ'] !== null
			&& (int)$delivery['SNAPSHOT_SEQ'] === (int)$receipt['SNAPSHOT_SEQ']
			&& (int)$delivery['RESULT_OBJECT_ID'] === (int)$receipt['RESULT_OBJECT_ID']
			&& ($delivery['RESULT_VERSION_ID'] === null ? null : (int)$delivery['RESULT_VERSION_ID'])
				=== ($receipt['RESULT_VERSION_ID'] === null ? null : (int)$receipt['RESULT_VERSION_ID'])
			&& ($delivery['RESULT_CONTENT_VERSION'] === null || $receipt['RESULT_CONTENT_VERSION'] === null
				|| (int)$delivery['RESULT_CONTENT_VERSION'] === (int)$receipt['RESULT_CONTENT_VERSION']);
	}

	private function isApplied(array $delivery): bool
	{
		return (int)($delivery['ID'] ?? 0) > 0 && (int)($delivery['RESULT_OBJECT_ID'] ?? 0) > 0
			&& ($delivery['OUTCOME'] ?? null) === DeliveryTable::OUTCOME_APPLIED
			&& in_array($delivery['STATUS'] ?? null, [DeliveryTable::STATUS_APPLIED, DeliveryTable::STATUS_DONE], true);
	}

	private function owner(array $delivery): Result
	{
		$data = [
			'ownerLedgerId' => (int)$delivery['ID'],
			'notified' => (int)$delivery['NOTIFIED'] === 1,
		];
		if (DeliveryTable::isSavedNotificationSkipped($delivery))
		{
			$data['skipped'] = true;
		}

		return (new Result())->setData($data);
	}

	private function failure(): Result
	{
		return (new Result())->addError(new Error(
			'Saved snapshot completion proof is inconsistent.', 'vibeoffice_saved_not_applied',
		));
	}
}
