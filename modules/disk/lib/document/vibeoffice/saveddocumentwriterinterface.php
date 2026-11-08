<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Main\Result;

interface SavedDocumentWriterInterface
{
	/** Prepare a reusable CFile outside SQL; ledgerId is the DeliveryTable primary key. */
	public function prepare(int $ledgerId, array $payload): Result;

	/** Commit content, receipt and APPLIED together, taking the object row before the document state. */
	public function apply(int $ledgerId, array $payload, array $stagedFile): Result;

	/**
	 * Resume notifications after commit, including repeated DONE deliveries.
	 * @param array{hostDocKey: string, editorKey: string, rev: int}|null $generation Classified event identity.
	 */
	public function complete(int $ledgerId, ?array $generation = null): Result;
}
