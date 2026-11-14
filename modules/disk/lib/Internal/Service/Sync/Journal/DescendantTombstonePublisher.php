<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Journal;

use Bitrix\Disk\Access\AccessCodeEnum;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Repository\Sync\SyncDescendantAccessRepository;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\SyncFeatureOption;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tombstones owed by the deletion of a folder to the holders of their own access to its descendants.
 *
 * The one record on the root of the cascade reaches only those who read the root. A descendant that
 * carried a right of its own - a sharing of a file, a right given in the rights window, an invitation
 * nobody answered - was seen by somebody the root says nothing to, and the rights that held it are torn
 * down object by object inside the cascade, so by the time the tree is gone nothing is left to read
 * them from.
 *
 * The work therefore falls on either side of the loop. Before it, one statement over the closure of
 * the path takes the own readable rights of the subtree, and the codes the root already addresses are
 * subtracted from them; after it, every descendant left with a code of its own is buried once, for
 * those codes alone. A holder of a folder inside the subtree gets no records of what lies under it:
 * the tombstone of the folder covers it on the client.
 *
 * Nothing here is allowed to break the deletion: a failure is logged to the channel of the contour and
 * the cascade goes on as if no descendant had carried a right of its own.
 */
final class DescendantTombstonePublisher
{
	private const LOGGER_CHANNEL = 'sync';

	private DescendantAccessSource $source;
	private JournalWriter $journal;
	private SyncFeatureOption $feature;
	private LoggerInterface $logger;

	public function __construct(
		?DescendantAccessSource $source = null,
		?JournalWriter $journal = null,
		?SyncFeatureOption $feature = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->source = $source ?? new SyncDescendantAccessRepository();
		$this->journal = $journal ?? new JournalWriter();
		$this->feature = $feature ?? new SyncFeatureOption();
		$this->logger = $logger ?? LoggerFactory::create(
			self::LOGGER_CHANNEL,
			feature: 'descendant tombstone publisher',
		);
	}

	/**
	 * The descendants of the root that someone outside the audience of the root holds by a right of the
	 * descendant itself, taken before the cascade tears those rights down.
	 *
	 * @param string[]|null $rootAudienceCodes the audience of the tombstone of the root, null when it was
	 *        not read - then nothing is subtracted
	 * @return array<int, array{resourceType: string, codes: list<string>}> keyed by the identifier of
	 *         the descendant
	 */
	public function capture(Folder $root, ?array $rootAudienceCodes): array
	{
		if (!$this->feature->isEnabled() || $root->isLink())
		{
			return [];
		}

		try
		{
			$rows = $this->source->ownReadableAccess((int)$root->getRealObjectId());
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the own access of the descendants was not read before the deletion: {message}',
				[
					'message' => $exception->getMessage(),
					'objectId' => $root->getRealObjectId(),
					'exception' => $exception,
				],
			);

			return [];
		}

		$rootCodes = array_fill_keys($rootAudienceCodes ?? [], true);
		$captured = [];
		foreach ($rows as $row)
		{
			$code = $row['accessCode'] === AccessCodeEnum::CREATOR->value
				? AccessCodeEnum::USER->value . $row['createdBy']
				: $row['accessCode']
			;
			if (isset($rootCodes[$code]))
			{
				continue;
			}

			$objectId = $row['objectId'];
			$captured[$objectId] ??= [
				'resourceType' => $row['isFolder'] ? ChangeRecord::RESOURCE_FOLDER : ChangeRecord::RESOURCE_FILE,
				'codes' => [],
			];
			if (!in_array($code, $captured[$objectId]['codes'], true))
			{
				$captured[$objectId]['codes'][] = $code;
			}
		}

		return $captured;
	}

	/**
	 * Buries every captured descendant once the cascade went through, each for its own codes alone.
	 *
	 * @param array<int, array{resourceType: string, codes: list<string>}> $captured {@see capture()}
	 */
	public function publishGone(array $captured, ?int $authorId): void
	{
		foreach ($captured as $objectId => $descendant)
		{
			try
			{
				$this->journal->publishTombstoneOfGoneObject(
					$descendant['resourceType'],
					(int)$objectId,
					TombstoneReason::Deleted,
					$authorId,
					$descendant['codes'],
				);
			}
			catch (Throwable $exception)
			{
				$this->logger->error(
					'the tombstone of a descendant was not published: {message}',
					[
						'message' => $exception->getMessage(),
						'objectId' => $objectId,
						'exception' => $exception,
					],
				);
			}
		}
	}

	/**
	 * The audience of the tombstone of an object a broken cascade took away: the audience of the root,
	 * joined by the codes of its own the object was captured with.
	 *
	 * @param array<int, array{resourceType: string, codes: list<string>}> $captured {@see capture()}
	 * @param string[]|null $rootAudienceCodes
	 * @return string[]|null
	 */
	public function audienceOfGoneObject(array $captured, int $objectId, ?array $rootAudienceCodes): ?array
	{
		if (!isset($captured[$objectId]))
		{
			return $rootAudienceCodes;
		}

		return array_values(array_unique(array_merge($rootAudienceCodes ?? [], $captured[$objectId]['codes'])));
	}
}
