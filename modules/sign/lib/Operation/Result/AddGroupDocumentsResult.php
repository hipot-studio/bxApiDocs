<?php

namespace Bitrix\Sign\Operation\Result;

use Bitrix\Main\Result;

/**
 * Outcome of the batch document creation inside a document group.
 *
 * Items keep the order of the incoming file list: partial success is a regular outcome,
 * so a failed file does not cancel the already created documents.
 */
final class AddGroupDocumentsResult extends Result
{
	public const ERROR_TARIFF_RESTRICTED = 'TARIFF_RESTRICTED';
	public const ERROR_BLANK_CREATE_FAILED = 'BLANK_CREATE_FAILED';
	public const ERROR_DOCUMENT_REGISTER_FAILED = 'DOCUMENT_REGISTER_FAILED';
	public const ERROR_ATTACH_FAILED = 'ATTACH_FAILED';
	public const ERROR_LIMIT_EXCEEDED = 'MAX_DOCUMENT_COUNT_EXCEEDED';

	private int $groupId = 0;

	/**
	 * @var array<int, array{
	 *     fileId: string,
	 *     success: bool,
	 *     uid: ?string,
	 *     documentId: ?int,
	 *     blankId: ?int,
	 *     hasPlaceholders: bool,
	 *     errorCode: ?string,
	 * }>
	 */
	private array $items = [];

	public function getGroupId(): int
	{
		return $this->groupId;
	}

	public function setGroupId(int $groupId): static
	{
		$this->groupId = $groupId;

		return $this;
	}

	public function addSuccessItem(
		string $fileId,
		string $uid,
		int $documentId,
		int $blankId,
		bool $hasPlaceholders = false,
	): static
	{
		$this->items[] = [
			'fileId' => $fileId,
			'success' => true,
			'uid' => $uid,
			'documentId' => $documentId,
			'blankId' => $blankId,
			'hasPlaceholders' => $hasPlaceholders,
			'errorCode' => null,
		];

		return $this;
	}

	public function addFailedItem(string $fileId, string $errorCode): static
	{
		$this->items[] = [
			'fileId' => $fileId,
			'success' => false,
			'uid' => null,
			'documentId' => null,
			'blankId' => null,
			'hasPlaceholders' => false,
			'errorCode' => $errorCode,
		];

		return $this;
	}

	/**
	 * Returns per-file outcomes in the order of the incoming file list.
	 *
	 * @return array<int, array{
	 *     fileId: string,
	 *     success: bool,
	 *     uid: ?string,
	 *     documentId: ?int,
	 *     blankId: ?int,
	 *     hasPlaceholders: bool,
	 *     errorCode: ?string,
	 * }>
	 */
	public function getItems(): array
	{
		return $this->items;
	}

	public function getSuccessCount(): int
	{
		return count(array_filter($this->items, static fn(array $item): bool => $item['success']));
	}
}
