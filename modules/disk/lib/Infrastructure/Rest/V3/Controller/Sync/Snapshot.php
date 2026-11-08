<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Controller\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\ObjectCardDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\SnapshotListRequest;
use Bitrix\Disk\Infrastructure\Rest\V3\Response\Sync\SnapshotListResponse;
use Bitrix\Disk\Internal\Service\Sync\Card\CardCollector;
use Bitrix\Disk\Internal\Service\Sync\Position\SnapshotToken;
use Bitrix\Disk\Internal\Service\Sync\Snapshot\AncestorFolderCollector;
use Bitrix\Disk\Internal\Service\Sync\Snapshot\SnapshotService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * disk.sync.snapshot.list - one page of the initial fetch.
 *
 * The page itself is decided by the fetch and its cards by the collector; what is left here is
 * putting the two sections of a page together and saying what did not make it into them.
 */
#[DtoType(ObjectCardDto::class)]
final class Snapshot extends BaseSyncController
{
	/**
	 * Why an object of a page arrives without a card: it was deleted between the fetch that named it
	 * and the assembly that describes it. A machine code and not a phrase - a client acts on a
	 * warning by reading the object back, it does not show it to anybody.
	 */
	private const WARNING_REASON_OBJECT_UNAVAILABLE = 'OBJECT_UNAVAILABLE';

	public function listAction(SnapshotListRequest $request): SnapshotListResponse
	{
		$userId = $this->resolveUserId();
		$sections = $request->getSections();

		$page = $this->getSnapshotService()->page(
			SnapshotToken::decode($request->getSnapshotToken(), $userId, $this->resolveAppId()),
			$request->getLimit(),
			$sections,
		);

		// The storages come with the page and are not asked about again: the fetch reads the set the
		// session fixed, so describing the page against a freshly computed set would describe it
		// against something else - and that computation is a pass over the storages of the whole
		// portal, on every page.
		$visibleStorageIds = $page['storageIds'];
		$collector = new CardCollector();

		// The checkpoints of the page travel beside the ancestors and by the same route: a folder
		// shared with the reader that holds nothing they may read is nobody's ancestor, and kinship
		// alone would leave it out of the fetch for good.
		$folderIds = (new AncestorFolderCollector())->collect(
			$userId,
			$page['objectIds'],
			$page['checkpointIds'],
		);

		// One assembly for both sections: the collector reads everything for the set it is given at
		// once, and the search for places inside it is bounded by the page rather than by the object.
		$cards = $collector->collect(
			$userId,
			[...$page['objectIds'], ...$folderIds],
			$visibleStorageIds,
			$sections,
		);

		[$itemCards, $folderCards] = self::split($cards, $folderIds);

		$linkedIds = self::foldersNamedByPaths($cards, [...$page['objectIds'], ...$folderIds]);
		$linkedCards = $collector->collect($userId, $linkedIds, $visibleStorageIds, $sections);

		return new SnapshotListResponse(
			self::collectionOf($itemCards),
			self::collectionOf([...$folderCards, ...$linkedCards]),
			$page['hasMore'],
			$page['token']->encode(),
			[
				// The streams of the fetch carry files; the folders come by kinship and are folders
				// by the query that found them. What a vanished object was cannot be read any more,
				// so the section it was asked for is what names its kind.
				...self::warnOfMissing($page['objectIds'], $itemCards, ObjectCardDto::TYPE_FILE),
				...self::warnOfMissing($folderIds, $folderCards, ObjectCardDto::TYPE_FOLDER),
				...self::warnOfMissing($linkedIds, $linkedCards, ObjectCardDto::TYPE_FOLDER),
			],
		);
	}

	/**
	 * The folders the paths of this page name and kinship does not reach.
	 *
	 * A folder of somebody else that the user reaches through a link of their own enters the path as
	 * that link and not as the folder behind it: the client keeps the link in its tree, under the
	 * link's own name. Such a row is nobody's ancestor - the object lies under the folder, not under
	 * the link - so the collector of ancestors cannot find it, and without a card of its own the node
	 * would point at a folder the client never saw. That is the very gap this section exists to close.
	 *
	 * Only the nodes of a path are taken, never parentFolderId on its own. A node exists only for a
	 * folder the user may read - the path is cut at the boundary of access - while parentFolderId is
	 * answered even for a folder above that boundary, on purpose, so that "somewhere you cannot
	 * describe" stays expressible. The collector of cards asks nothing about rights, so a card built
	 * for such an identifier would publish the name of a folder the user may not read. Every
	 * parentFolderId that may legitimately have a card is the deepest node of its own path, so the
	 * nodes are exactly the publishable set.
	 *
	 * One pass is enough: the route of a link is the head of the route built on top of it, so the
	 * paths of the page already carry every link above them.
	 *
	 * @param ObjectCardDto[] $cards
	 * @param int[] $covered what the page already answers with
	 * @return int[] ascending, deduplicated; empty when the page was not asked for the section of
	 *         location, because then there are no paths and nothing can dangle
	 */
	private static function foldersNamedByPaths(array $cards, array $covered): array
	{
		$known = array_flip($covered);

		$ids = [];
		foreach ($cards as $card)
		{
			// Absent entirely when the card was not asked for the section of location.
			if (!isset($card->places))
			{
				continue;
			}

			foreach ($card->places as $place)
			{
				foreach ($place->path as $node)
				{
					if (!isset($known[$node->id]))
					{
						$ids[$node->id] = $node->id;
					}
				}
			}
		}

		ksort($ids);

		return array_values($ids);
	}

	/**
	 * The service comes from the locator and not from a constructor of its own: the confirmation of
	 * the source of access is a seam of the fetch (ALG-05), and wiring an implementation of it has to
	 * be a matter of configuration rather than of editing this method.
	 */
	private function getSnapshotService(): SnapshotService
	{
		return ServiceLocator::getInstance()->get(SnapshotService::class);
	}

	/**
	 * @param ObjectCardDto[] $cards in the order the objects were asked about
	 * @param int[] $folderIds
	 * @return array{0: ObjectCardDto[], 1: ObjectCardDto[]}
	 */
	private static function split(array $cards, array $folderIds): array
	{
		$isFolderOfThePage = array_flip($folderIds);

		$itemCards = [];
		$folderCards = [];
		foreach ($cards as $card)
		{
			if (isset($isFolderOfThePage[$card->objectId]))
			{
				$folderCards[] = $card;
			}
			else
			{
				$itemCards[] = $card;
			}
		}

		return [$itemCards, $folderCards];
	}

	/**
	 * @param int[] $askedFor
	 * @param ObjectCardDto[] $cards
	 * @return array<int, array{resourceType: string, resourceId: int, reason: string}>
	 */
	private static function warnOfMissing(array $askedFor, array $cards, string $resourceType): array
	{
		$warnings = [];
		foreach (array_diff($askedFor, array_column($cards, 'objectId')) as $resourceId)
		{
			$warnings[] = [
				'resourceType' => $resourceType,
				'resourceId' => $resourceId,
				'reason' => self::WARNING_REASON_OBJECT_UNAVAILABLE,
			];
		}

		return $warnings;
	}

	/**
	 * @param ObjectCardDto[] $cards
	 */
	private static function collectionOf(array $cards): DtoCollection
	{
		$collection = new DtoCollection(ObjectCardDto::class);
		foreach ($cards as $card)
		{
			$collection->add($card);
		}

		return $collection;
	}
}
