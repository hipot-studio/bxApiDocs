<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\LargeAttachment;

use Bitrix\Mail\Integration\Disk\Dto\LargeAttachmentResult;
use Bitrix\Mail\Integration\Disk\LargeAttachmentStorageInterface;
use Bitrix\Mail\Internal\Service\LargeAttachment\LargeAttachmentService;
use Bitrix\Mail\Public\Service\LargeAttachment\Dto\SendContractResult;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Validates large attachment contracts before files are excluded from an outgoing message.
 */
final class SendContractValidator
{
	public const RESULT_KEY = 'result';
	public const MAX_CONTRACT_COUNT = 10;
	public const MAX_FILE_COUNT = LargeAttachmentService::MAX_FILE_COUNT;

	public const ERROR_INVALID_SEND_CONTRACT = 'MAIL_LA_INVALID_SEND_CONTRACT';
	public const ERROR_LINK_MISSING = 'MAIL_LA_LINK_MISSING';
	public const ERROR_INVALID_SEND_RESULT = 'MAIL_LA_INVALID_SEND_RESULT';
	public const ERROR_INVALID_TOKEN = 'MAIL_LA_INVALID_TOKEN';
	public const ERROR_ACCESS_DENIED = 'MAIL_LA_ACCESS_DENIED';
	public const ERROR_LINK_UNAVAILABLE = 'MAIL_LA_LINK_UNAVAILABLE';
	public const ERROR_TARIFF_UNAVAILABLE = 'MAIL_LA_TARIFF_UNAVAILABLE';
	public const ERROR_INVALID_CONTEXT = 'MAIL_LA_INVALID_CONTEXT';

	public function __construct(
		private readonly ?LargeAttachmentStorageInterface $storage = null,
	)
	{
	}

	/**
	 * Validates all contracts atomically for the current sender and outgoing HTML.
	 *
	 * @param array<int, array{token?: mixed, fileIds?: mixed}> $contracts
	 * @param mixed[] $allowedFileIds
	 *
	 * @return Result
	 */
	public function validate(
		int $userId,
		array $contracts,
		array $allowedFileIds,
		string $messageBody,
	): Result
	{
		$result = new Result();
		if (!$contracts)
		{
			return $this->success([], []);
		}

		if (count($contracts) > self::MAX_CONTRACT_COUNT)
		{
			return $this->invalidContract();
		}

		$allowedFileIds = $this->normalizeAllowedFileIds($allowedFileIds);
		$claimedFileIds = [];
		$normalizedContracts = [];
		$totalFileCount = 0;

		foreach ($contracts as $contract)
		{
			$token = is_array($contract) && is_string($contract['token'] ?? null)
				? $contract['token']
				: '';
			$fileIds = is_array($contract) && is_array($contract['fileIds'] ?? null)
				? $this->normalizeContractFileIds($contract['fileIds'])
				: null;

			if ($token === '' || $fileIds === null)
			{
				return $this->invalidContract();
			}

			$totalFileCount += count($fileIds);
			if ($totalFileCount > self::MAX_FILE_COUNT)
			{
				return $this->invalidContract();
			}

			foreach ($fileIds as $fileId)
			{
				if (!isset($allowedFileIds[$fileId]) || isset($claimedFileIds[$fileId]))
				{
					return $this->invalidContract();
				}

				$claimedFileIds[$fileId] = true;
			}

			$normalizedContracts[] = [
				'token' => $token,
				'fileIds' => $fileIds,
			];
		}

		$messageUrls = $this->extractMessageUrls($messageBody);
		$confirmedFileIds = [];
		$externalLinkIds = [];

		foreach ($normalizedContracts as $contract)
		{
			$resolveResult = LargeAttachmentService::resolveForSend(
				$userId,
				$contract['token'],
				$contract['fileIds'],
				$this->storage,
			);
			if (!$resolveResult->isSuccess())
			{
				return $result->addErrors($resolveResult->getErrors());
			}

			$resolved = $resolveResult->getData()[LargeAttachmentService::RESULT_KEY] ?? null;
			if (!$resolved instanceof LargeAttachmentResult)
			{
				return $this->invalidSendResult();
			}

			$resolvedFileIds = $this->normalizeContractFileIds($resolved->fileIds);
			if (
				$resolved->token !== $contract['token']
				|| $resolved->publicUrl === ''
				|| $resolved->externalLinkId === null
				|| $resolved->externalLinkId <= 0
				|| $resolvedFileIds === null
				|| $resolvedFileIds !== $contract['fileIds']
			)
			{
				return $this->invalidSendResult();
			}

			if (!isset($messageUrls[$resolved->publicUrl]))
			{
				return (new Result())->addError(new Error(
					'Large attachment link is missing from the message body.',
					self::ERROR_LINK_MISSING,
				));
			}

			foreach ($contract['fileIds'] as $fileId)
			{
				$confirmedFileIds[$fileId] = true;
			}
			$externalLinkIds[$resolved->externalLinkId] = true;
		}

		return $this->success(
			array_keys($confirmedFileIds),
			array_keys($externalLinkIds),
		);
	}

	/**
	 * @param mixed[] $fileIds
	 *
	 * @return array<int, true>
	 */
	private function normalizeAllowedFileIds(array $fileIds): array
	{
		$normalized = [];
		foreach ($fileIds as $fileId)
		{
			if (
				(is_int($fileId) || is_string($fileId))
				&& preg_match('/^n?(\d+)$/i', (string)$fileId, $matches)
				&& (int)$matches[1] > 0
			)
			{
				$normalized[(int)$matches[1]] = true;
			}
		}

		return $normalized;
	}

	/**
	 * @param mixed[] $fileIds
	 *
	 * @return int[]|null
	 */
	private function normalizeContractFileIds(array $fileIds): ?array
	{
		if (!$fileIds)
		{
			return null;
		}

		$normalized = [];
		foreach ($fileIds as $fileId)
		{
			if (
				(!is_int($fileId) && !is_string($fileId))
				|| preg_match('/^[0-9]+$/D', (string)$fileId) !== 1
				|| (int)$fileId <= 0
			)
			{
				return null;
			}

			$fileId = (int)$fileId;
			if (isset($normalized[$fileId]))
			{
				return null;
			}

			$normalized[$fileId] = true;
		}

		$fileIds = array_keys($normalized);
		sort($fileIds, SORT_NUMERIC);

		return $fileIds;
	}

	/**
	 * @return array<string, true>
	 */
	private function extractMessageUrls(string $messageBody): array
	{
		$charset = defined('SITE_CHARSET') ? SITE_CHARSET : 'UTF-8';
		$messageBody = html_entity_decode($messageBody, ENT_QUOTES | ENT_HTML5, $charset);
		preg_match_all('~https?://[^\s<>"\']+~iu', $messageBody, $matches);

		return array_fill_keys($matches[0] ?? [], true);
	}

	/**
	 * @param int[] $fileIds
	 * @param int[] $externalLinkIds
	 */
	private function success(array $fileIds, array $externalLinkIds): Result
	{
		$result = new Result();
		$result->setData([
			self::RESULT_KEY => new SendContractResult($fileIds, $externalLinkIds),
		]);

		return $result;
	}

	private function invalidContract(): Result
	{
		return (new Result())->addError(new Error(
			'Large attachment send contract is invalid.',
			self::ERROR_INVALID_SEND_CONTRACT,
		));
	}

	private function invalidSendResult(): Result
	{
		return (new Result())->addError(new Error(
			'Large attachment storage returned an invalid send result.',
			self::ERROR_INVALID_SEND_RESULT,
		));
	}
}
