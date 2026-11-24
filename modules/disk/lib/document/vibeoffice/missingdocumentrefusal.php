<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Vibeoffice\Logging\DatabaseFailureResult;
use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookEventPolicy;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookProcessor;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

final class MissingDocumentRefusal
{
	public function __construct(private readonly DocumentStateRepository $repository)
	{
	}

	/** The caller holds the delivery lock; only our own confirmed commit permits a refusal. */
	public function tryRefuse(int $ledgerId, int $deliveryId, array $payload): Result
	{
		$classification = (new WebhookEventPolicy())->classify(WebhookProcessor::EVENT_SAVED, $payload);
		$policy = $classification->getData();
		$failedCheck = match (true)
		{
			!$classification->isSuccess() => 'invalid_payload',
			$policy['legacy'] => 'legacy_payload',
			!$policy['identityKnown'] => 'unknown_snapshot_identity',
			!is_int($policy['snapshotSeq']) || $policy['snapshotSeq'] <= 0 => 'invalid_snapshot_seq',
			$ledgerId <= 0 || $deliveryId <= 0 => 'invalid_delivery_id',
			default => null,
		};
		if ($failedCheck !== null)
		{
			return $this->skipRefusal($failedCheck);
		}

		try
		{
			if (DocumentStateRepository::hasOpenTransaction())
			{
				return (new Result())
					->addError(new Error(
						'A saved refusal requires its own transaction.', 'vibeoffice_transaction_active',
					))
					->setData(['refusalCheck' => 'transaction_active'])
				;
			}

			$result = $this->repository->withLockedDocument(
				$policy['hostDocKey'],
				function (array $state) use ($ledgerId, $deliveryId, $policy): Result {
					if ((int)($state['EDIT_FENCE_STATE'] ?? 0) === 2)
					{
						return $this->skipRefusal('edit_fence_blocked');
					}
					if (!HostDocumentKey::matchesStableEditScope($state, $policy['hostDocKey'])
						&& !HostDocumentKey::matchesSharedEditScope($state, $policy['hostDocKey']))
					{
						return $this->skipRefusal('scope_mismatch');
					}
					$object = DocumentStateRepository::fetchForUpdate(
						ObjectTable::query()->setSelect(['ID'])->where('ID', (int)$state['OBJECT_ID']),
					);
					if ($object !== null)
					{
						return $this->skipRefusal('object_exists');
					}
					$delivery = DocumentStateRepository::fetchForUpdate(
						DeliveryTable::query()->setSelect(['*'])->where('ID', $ledgerId),
					);
					if (!$delivery)
					{
						return $this->skipRefusal('delivery_missing');
					}
					if ((int)$delivery['DELIVERY_ID'] !== $deliveryId
						|| $delivery['EVENT'] !== WebhookProcessor::EVENT_SAVED
						|| $delivery['HOST_DOC_KEY'] !== $policy['hostDocKey']
						|| (int)$delivery['SNAPSHOT_SEQ'] !== $policy['snapshotSeq'])
					{
						return $this->skipRefusal('delivery_mismatch');
					}
					if (!in_array($delivery['STATUS'], [DeliveryTable::STATUS_PENDING, DeliveryTable::STATUS_STAGED], true))
					{
						return $this->skipRefusal('delivery_status_mismatch');
					}
					if (!in_array($delivery['OUTCOME'], [null, ''], true) || !empty($delivery['RESULT_OBJECT_ID'])
						|| !empty($delivery['RESULT_VERSION_ID']) || (int)$delivery['NOTIFIED'] !== 0)
					{
						return $this->skipRefusal('delivery_has_result');
					}
					if ($policy['snapshotSeq'] <= (int)$state['LAST_SNAPSHOT_SEQ'])
					{
						return $this->skipRefusal('snapshot_not_newer');
					}
					if ($this->repository->getSnapshot($policy['hostDocKey'], $policy['snapshotSeq']) !== null)
					{
						return $this->skipRefusal('snapshot_receipt_exists');
					}
					$updated = DeliveryTable::update($ledgerId, [
						'STATUS' => DeliveryTable::STATUS_DONE,
						'OUTCOME' => DeliveryTable::OUTCOME_REFUSED,
						'REASON' => 'file_missing',
						'PROCESSED_TIME' => new DateTime(),
					]);

					return $updated->isSuccess()
						? (new Result())->setData(['refused' => true])
						: (new Result())->addErrors($updated->getErrors())->setData(['refusalCheck' => 'ledger_write_failed'])
					;
				},
				lockObject: false,
				auditFileChanges: false,
			);
			if (!$result->isSuccess() && !isset($result->getData()['refusalCheck']))
			{
				$check = match ($result->getError()?->getCode())
				{
					'vibeoffice_document_not_found' => 'state_missing',
					'vibeoffice_lock_timeout' => 'lock_timeout',
					default => 'transaction_failed',
				};
				$result->setData($result->getData() + ['refusalCheck' => $check]);
			}

			return $result;
		}
		catch (\Throwable $exception)
		{
			return DatabaseFailureResult::fromException(
				$exception, new Error('Could not persist the saved refusal.', 'vibeoffice_state_failed'),
			)->setData(['refusalCheck' => 'transaction_failed']);
		}
	}

	private function skipRefusal(string $failedCheck): Result
	{
		return (new Result())->setData([
			'refused' => false,
			'refusalCheck' => $failedCheck,
		]);
	}
}
