<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookProcessor;
use Bitrix\Disk\File;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

final class SavedNotificationSkip
{
	public function __construct(private readonly DocumentStateRepository $repository)
	{
	}

	/** The caller serializes notification completion for the snapshot. */
	public function trySkip(array $delivery): Result
	{
		$key = (string)$delivery['HOST_DOC_KEY'];
		$unchanged = (new Result())->setData(['skipped' => false]);
		if (!$this->isPending($delivery))
		{
			return $unchanged;
		}
		if (DocumentStateRepository::hasOpenTransaction())
		{
			return (new Result())->addError(new Error(
				'A saved notification skip requires its own transaction.', 'vibeoffice_transaction_active',
			));
		}

		return $this->repository->withLockedDocument($key, function (array $state, ?File $lockedFile) use ($delivery, $unchanged): Result {
			$objectId = (int)$delivery['RESULT_OBJECT_ID'];
			if ((int)$state['OBJECT_ID'] !== $objectId
				|| (!HostDocumentKey::matchesStableEditScope($state, $delivery['HOST_DOC_KEY'])
					&& !HostDocumentKey::matchesSharedEditScope($state, $delivery['HOST_DOC_KEY'])))
			{
				return $unchanged;
			}

			if ($lockedFile !== null)
			{
				return $unchanged;
			}

			$ledgerId = (int)$delivery['ID'];
			$fresh = DocumentStateRepository::fetchForUpdate(
				DeliveryTable::query()->setSelect(['*'])->where('ID', $ledgerId),
			);
			if (!$fresh || !$this->isPending($fresh) || $fresh['HOST_DOC_KEY'] !== $delivery['HOST_DOC_KEY']
				|| (int)$fresh['DELIVERY_ID'] !== (int)$delivery['DELIVERY_ID']
				|| (int)$fresh['SNAPSHOT_SEQ'] !== (int)$delivery['SNAPSHOT_SEQ']
				|| (int)$fresh['RESULT_OBJECT_ID'] !== $objectId
				|| (int)$fresh['RESULT_VERSION_ID'] !== (int)$delivery['RESULT_VERSION_ID'])
			{
				return $unchanged;
			}

			$completion = (new SavedSnapshotCompletion())->resolve($fresh, requireReceipt: true);
			if (!$completion->isSuccess())
			{
				return $completion;
			}
			if ($completion->getData()['notified'])
			{
				return $unchanged;
			}

			$updated = DeliveryTable::update($ledgerId, [
				'REASON' => DeliveryTable::REASON_NOTIFICATION_RESULT_DELETED,
				'PROCESSED_TIME' => new DateTime(),
			]);

			return $updated->isSuccess() ? (new Result())->setData(['skipped' => true]) : $updated;
		});
	}

	private function isPending(array $delivery): bool
	{
		return $delivery['EVENT'] === WebhookProcessor::EVENT_SAVED
			&& $delivery['STATUS'] === DeliveryTable::STATUS_DONE
			&& $delivery['OUTCOME'] === DeliveryTable::OUTCOME_APPLIED
			&& (int)$delivery['NOTIFIED'] === 0
			&& in_array($delivery['REASON'], [null, ''], true)
			&& (int)$delivery['SNAPSHOT_SEQ'] > 0
			&& (int)$delivery['RESULT_OBJECT_ID'] > 0
			&& (int)$delivery['RESULT_VERSION_ID'] > 0;
	}
}
