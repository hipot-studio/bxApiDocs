<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\ScheduledMessage;

use Bitrix\Mail\Public\Service\LargeAttachment\Dto\SendContractResult;
use Bitrix\Mail\Public\Service\LargeAttachment\SendContractValidator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ScheduledMessageLargeAttachmentService
{
	private const MAX_TOKEN_LENGTH = 4096;
	private const MAX_URL_LENGTH = 2048;

	public function __construct(
		private readonly SendContractValidator $validator = new SendContractValidator(),
	)
	{
	}

	/**
	 * @param array<int, array<string, mixed>> $largeAttachments
	 * @param array<int, array<string, mixed>> $attachments
	 */
	public function prepare(
		int $userId,
		array $largeAttachments,
		array $attachments,
		string $body,
	): Result
	{
		if ($largeAttachments === [])
		{
			return (new Result())->setData([
				'largeAttachments' => [],
				'sourceFileIds' => [],
				'externalLinkIds' => [],
			]);
		}
		if (count($largeAttachments) > SendContractValidator::MAX_CONTRACT_COUNT)
		{
			return $this->invalidContract();
		}

		$allowedSourceIds = [];
		foreach ($attachments as $attachment)
		{
			$sourceId = (int)($attachment['OBJECT_ID'] ?? $attachment['SOURCE_OBJECT_ID'] ?? 0);
			if ($sourceId > 0)
			{
				$allowedSourceIds[$sourceId] = true;
			}
		}

		$normalized = [];
		$contracts = [];
		$claimedFileIds = [];
		foreach (array_values($largeAttachments) as $largeAttachment)
		{
			if (!is_array($largeAttachment))
			{
				return $this->invalidContract();
			}

			$token = trim((string)($largeAttachment['token'] ?? ''));
			$publicUrl = trim((string)($largeAttachment['publicUrl'] ?? ''));
			$fileIds = $this->normalizeIds($largeAttachment['fileIds'] ?? null);
			$sourceFileIds = $this->normalizeIds($largeAttachment['sourceFileIds'] ?? $fileIds);
			$tokenFileIds = $this->normalizeIds($largeAttachment['tokenFileIds'] ?? $sourceFileIds);
			if (
				$token === ''
				|| strlen($token) > self::MAX_TOKEN_LENGTH
				|| $publicUrl === ''
				|| strlen($publicUrl) > self::MAX_URL_LENGTH
				|| $fileIds === null
				|| $sourceFileIds === null
				|| $tokenFileIds === null
				|| count($fileIds) !== count($sourceFileIds)
				|| count($fileIds) !== count($tokenFileIds)
				|| count($fileIds) > SendContractValidator::MAX_FILE_COUNT
			)
			{
				return $this->invalidContract();
			}

			foreach ($fileIds as $fileId)
			{
				if (!isset($allowedSourceIds[$fileId]) || isset($claimedFileIds[$fileId]))
				{
					return $this->invalidContract();
				}
				$claimedFileIds[$fileId] = true;
			}

			$normalized[] = [
				'token' => $token,
				'publicUrl' => $publicUrl,
				'fileIds' => $fileIds,
				'sourceFileIds' => $fileIds,
				'tokenFileIds' => $tokenFileIds,
			];
			$contracts[] = [
				'token' => $token,
				'fileIds' => $fileIds,
				'sourceFileIds' => $tokenFileIds,
			];
		}

		$validationResult = $this->validator->validate(
			$userId,
			$contracts,
			array_keys($allowedSourceIds),
			$body,
		);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$contract = $validationResult->getData()[SendContractValidator::RESULT_KEY] ?? null;
		if (!$contract instanceof SendContractResult)
		{
			return (new Result())->addError(new Error(
				'Large attachment validator returned an invalid result.',
				SendContractValidator::ERROR_INVALID_SEND_RESULT,
			));
		}

		return (new Result())->setData([
			'largeAttachments' => $normalized,
			'sourceFileIds' => array_values(array_unique($contract->fileIds)),
			'externalLinkIds' => array_values(array_unique($contract->externalLinkIds)),
		]);
	}

	/** @return int[]|null */
	private function normalizeIds(mixed $ids): ?array
	{
		if (!is_array($ids) || $ids === [])
		{
			return null;
		}

		$normalized = [];
		foreach ($ids as $id)
		{
			if ((!is_int($id) && !is_string($id)) || preg_match('/^\d+$/', (string)$id) !== 1)
			{
				return null;
			}

			$id = (int)$id;
			if ($id <= 0 || isset($normalized[$id]))
			{
				return null;
			}
			$normalized[$id] = true;
		}

		$ids = array_keys($normalized);
		sort($ids, SORT_NUMERIC);

		return $ids;
	}

	private function invalidContract(): Result
	{
		return (new Result())->addError(new Error(
			'Large attachment send contract is invalid.',
			SendContractValidator::ERROR_INVALID_SEND_CONTRACT,
		));
	}
}
