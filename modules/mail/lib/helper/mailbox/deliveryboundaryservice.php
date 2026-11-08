<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\Type\DateTime;
use Psr\Log\LoggerInterface;

final class DeliveryBoundaryService
{
	/** @var array<string, DeliveryBoundary|null> */
	private array $boundaries = [];
	/** @var array<int, bool> */
	private array $enabledDirectories = [];

	private ?bool $mailboxEnabled = null;

	public function __construct(
		private readonly int $mailboxId,
		private readonly ?LoggerInterface $logger = null,
	)
	{
	}

	public function resolve(
		int $dirId,
		string $dirMd5,
		int $generationId,
		int $uidValidity,
		?callable $serverMaximumUidResolver,
	): ?DeliveryBoundary
	{
		$key = $this->buildCacheKey($dirId, $generationId, $uidValidity);
		if (array_key_exists($key, $this->boundaries))
		{
			return $this->boundaries[$key];
		}

		$row = $this->readRow($dirId);
		$this->enabledDirectories[$dirId] = $row !== null;
		$stored = DeliveryBoundary::fromStoredValue((string)($row['VALUE'] ?? ''));
		if ($stored?->belongsTo($generationId, $uidValidity))
		{
			return $this->boundaries[$key] = $stored;
		}

		$localMaximumUid = $this->findLocalMaximumUid($dirMd5, $generationId, $uidValidity);
		if ($localMaximumUid === null && $row === null && !$this->isMailboxEnabled())
		{
			return $this->boundaries[$key] = null;
		}

		$serverMaximumUid = $localMaximumUid === null && $serverMaximumUidResolver !== null
			? $serverMaximumUidResolver()
			: null
		;
		if ($localMaximumUid === null && $serverMaximumUid === null)
		{
			return $this->boundaries[$key] = null;
		}

		$boundary = new DeliveryBoundary(
			$generationId,
			$uidValidity,
			$localMaximumUid ?? max(0, $serverMaximumUid),
		);
		$this->enabledDirectories[$dirId] = true;
		try
		{
			$this->store($dirId, $boundary, $row !== null);
		}
		catch (\Throwable $exception)
		{
			$this->logger?->error($exception->getMessage(), ['exception' => $exception]);

			return $this->boundaries[$key] = null;
		}

		$winner = DeliveryBoundary::fromStoredValue((string)($this->readRow($dirId)['VALUE'] ?? ''));
		if (!$winner?->belongsTo($generationId, $uidValidity))
		{
			return $this->boundaries[$key] = null;
		}

		return $this->boundaries[$key] = $winner;
	}

	public function isMailboxEnabled(): bool
	{
		if ($this->mailboxEnabled !== null)
		{
			return $this->mailboxEnabled;
		}

		$this->mailboxEnabled = MailEntityOptionsTable::getRow([
			'select' => ['VALUE'],
			'filter' => [
				'=MAILBOX_ID' => $this->mailboxId,
				'=ENTITY_TYPE' => MailEntityOptionsTable::MAILBOX_TYPE_NAME,
				'=ENTITY_ID' => (string)$this->mailboxId,
				'=PROPERTY_NAME' => MailEntityOptionsTable::DELIVERY_BOUNDARY_ENABLED_PROPERTY_NAME,
			],
		]) !== null;

		return $this->mailboxEnabled;
	}

	public function isDirectoryEnabled(int $dirId): bool
	{
		if (array_key_exists($dirId, $this->enabledDirectories))
		{
			return $this->enabledDirectories[$dirId];
		}

		return $this->enabledDirectories[$dirId] = $this->readRow($dirId) !== null;
	}

	private function findLocalMaximumUid(string $dirMd5, int $generationId, int $uidValidity): ?int
	{
		$row = MailMessageUidTable::getRow([
			'select' => ['MSG_UID'],
			'filter' => [
				'=MAILBOX_ID' => $this->mailboxId,
				'=GENERATION_ID' => $generationId,
				'=DIR_MD5' => $dirMd5,
				'=DIR_UIDV' => $uidValidity,
			],
			'order' => ['MSG_UID' => 'DESC'],
		]);

		return $row === null ? null : (int)$row['MSG_UID'];
	}

	private function readRow(int $dirId): ?array
	{
		return MailEntityOptionsTable::getRow([
			'select' => ['VALUE'],
			'filter' => [
				'=MAILBOX_ID' => $this->mailboxId,
				'=ENTITY_TYPE' => MailEntityOptionsTable::DIR_TYPE_NAME,
				'=ENTITY_ID' => (string)$dirId,
				'=PROPERTY_NAME' => MailEntityOptionsTable::DELIVERY_BOUNDARY_PROPERTY_NAME,
			],
		]);
	}

	private function store(int $dirId, DeliveryBoundary $boundary, bool $exists): void
	{
		if (!$exists)
		{
			MailEntityOptionsTable::insertIgnore(
				$this->mailboxId,
				$dirId,
				MailEntityOptionsTable::DIR_TYPE_NAME,
				MailEntityOptionsTable::DELIVERY_BOUNDARY_PROPERTY_NAME,
				$boundary->toStoredValue(),
			);

			return;
		}

		MailEntityOptionsTable::update(
			$this->getPrimary($dirId),
			[
				'VALUE' => $boundary->toStoredValue(),
				'DATE_INSERT' => new DateTime(),
			],
		);
	}

	private function getPrimary(int $dirId): array
	{
		return [
			'MAILBOX_ID' => $this->mailboxId,
			'ENTITY_TYPE' => MailEntityOptionsTable::DIR_TYPE_NAME,
			'ENTITY_ID' => (string)$dirId,
			'PROPERTY_NAME' => MailEntityOptionsTable::DELIVERY_BOUNDARY_PROPERTY_NAME,
		];
	}

	private function buildCacheKey(int $dirId, int $generationId, int $uidValidity): string
	{
		return $dirId . ':' . $generationId . ':' . $uidValidity;
	}
}
