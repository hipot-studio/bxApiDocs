<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

final class TerminalProof
{
	public const RECEIPT_FIELDS = [
		'SNAPSHOT_SEQ', 'RESULT_OBJECT_ID', 'RESULT_VERSION_ID', 'RESULT_CONTENT_VERSION',
		'CONTENT_SHA256', 'DELIVERY_ID', 'SOURCE',
	];

	public static function fromSaved(array $state, array $placement, int $deliveryId, array $receipt): array
	{
		return self::create('saved', $state, $placement, $deliveryId) + [
			'receipt' => array_intersect_key($receipt, array_flip(self::RECEIPT_FIELDS)),
		];
	}

	public static function fromNotModified(array $state, array $placement, int $deliveryId): array
	{
		return self::create('notmodified', $state, $placement, $deliveryId);
	}

	public static function matchesState(array $proof, array $state): bool
	{
		return ($proof['schemaVersion'] ?? null) === 1
			&& in_array($proof['kind'] ?? null, ['saved', 'notmodified'], true)
			&& ($proof['hostDocKey'] ?? null) === $state['HOST_DOC_KEY']
			&& is_string($proof['editorKey'] ?? null) && $proof['editorKey'] !== ''
			&& is_int($proof['rev'] ?? null) && $proof['rev'] >= 0
			&& is_int($proof['deliveryId'] ?? null) && $proof['deliveryId'] > 0
			&& ($proof['baseline'] ?? null) === self::baseline($state);
	}

	/** Verify the exact persisted receipt without producing another terminal proof. */
	public static function matchesReceipt(array $proof, array $state, DocumentStateRepository $repository): bool
	{
		if ($proof['kind'] === 'notmodified')
		{
			return !array_key_exists('receipt', $proof);
		}
		$receipt = $proof['receipt'] ?? null;
		if (!is_array($receipt) || count($receipt) !== count(TerminalProof::RECEIPT_FIELDS)
			|| (int)($receipt['SNAPSHOT_SEQ'] ?? 0) <= 0 || (int)($receipt['RESULT_OBJECT_ID'] ?? 0) <= 0)
		{
			return false;
		}
		$stored = $repository->getSnapshot($state['HOST_DOC_KEY'], (int)$receipt['SNAPSHOT_SEQ']);
		if (!$stored || array_intersect_key($stored, array_flip(TerminalProof::RECEIPT_FIELDS)) !== $receipt)
		{
			return false;
		}
		$objectId = (int)$receipt['RESULT_OBJECT_ID'];

		return ($objectId === (int)$state['OBJECT_ID'] && (int)$receipt['RESULT_VERSION_ID'] > 0)
			|| ((int)$state['CONFLICTED'] === 1 && $objectId === (int)$state['CONFLICT_OBJECT_ID']);
	}

	private static function create(string $kind, array $state, array $placement, int $deliveryId): array
	{
		return [
			'schemaVersion' => 1,
			'kind' => $kind,
			'hostDocKey' => $state['HOST_DOC_KEY'],
			'editorKey' => $placement['editorKey'],
			'rev' => $placement['rev'],
			'deliveryId' => $deliveryId,
			'baseline' => self::baseline($state),
		];
	}

	private static function baseline(array $state): array
	{
		return [
			'fileId' => (int)$state['EXPECTED_FILE_ID'],
			'contentVersion' => (int)$state['EXPECTED_CONTENT_VERSION'],
			'etag' => (string)$state['EXPECTED_ETAG'],
		];
	}
}
