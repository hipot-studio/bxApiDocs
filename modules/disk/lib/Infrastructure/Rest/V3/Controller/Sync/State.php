<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Controller\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\CheckItemDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\ObjectCardDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\StateCheckRequest;
use Bitrix\Disk\Infrastructure\Rest\V3\Response\Sync\StateCheckResponse;
use Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync\SectionsStructure;
use Bitrix\Disk\Internal\Service\Sync\Card\CardCollector;
use Bitrix\Disk\Internal\Service\Sync\Check\CheckVerdict;
use Bitrix\Disk\Internal\Service\Sync\Check\ListModeChecker;
use Bitrix\Disk\Internal\Service\Sync\Check\ScopeModeChecker;
use Bitrix\Disk\Internal\Service\Sync\Position\CheckToken;
use Bitrix\Disk\Internal\Service\Sync\StorageListProvider;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * disk.sync.state.check - what is now true of the objects a client names, or of an area it names.
 *
 * The two modes are one method because they answer one question, and the client asks it in whichever
 * terms it has: a change of access named an area, an expired cursor left it with a memory and no way
 * back into the journal. Which objects the answer is about is decided by the checkers; what is left
 * here is naming the pair, building the position of a walk that has none yet, and describing the
 * objects the verdicts declared visible.
 *
 * The check answers about the state of right now and about nothing else: it moves no cursor, reads no
 * journal, needs no live session and touches none. Its position is its own kind of token with a
 * boundary of state and a life of its own, and an expired one sends the client back into a check
 * rather than into recreating a session it never used.
 */
#[DtoType(CheckItemDto::class)]
final class State extends BaseSyncController
{
	/**
	 * How long the position of a walk over an area is accepted for.
	 *
	 * A day, and never longer than the journal keeps its records. The life is counted from the boundary
	 * of state the position carries rather than from the moment the position was handed out: anchored
	 * there, the distance between the boundary and the expiry is that ceiling exactly, whatever width
	 * the boundary steps back by. Below that ceiling a day is the trade: a client switched off in the
	 * middle of a pass resumes it, and one switched off for longer starts a pass against a fresh
	 * boundary rather than reasoning about a stale one.
	 */
	private const TOKEN_TTL_SECONDS = 86400;

	public function checkAction(StateCheckRequest $request): StateCheckResponse
	{
		$userId = $this->resolveUserId();
		$sections = $request->getSections();

		if ($request->isOverList())
		{
			$verdicts = (new ListModeChecker())->check($userId, $request->getObjectIds());

			return StateCheckResponse::ofList($this->itemsOf($userId, $verdicts, $sections));
		}

		$page = (new ScopeModeChecker())->check(
			$this->positionOf($request, $userId, $this->resolveAppId()),
			$request->getLimit(),
		);

		return StateCheckResponse::ofScope(
			$this->itemsOf($userId, $page['verdicts'], $sections),
			$page['hasMore'],
			$page['token']->encode(),
		);
	}

	/**
	 * The position the page of an area is read at: the one the client gave back, or the one this call
	 * opens the walk with.
	 *
	 * The boundary of state stands a clock tolerance behind the current instant, and the drift of the
	 * clocks is all it defends against: `UPDATE_TIME` is stamped by php on the node performing the
	 * mutation, while the boundary is computed by php on the node answering this call.
	 *
	 * It is deliberately not the safe window of the journal. What a walk drops for having changed above
	 * the boundary is not something the journal owes the client: a record is addressed to the audience
	 * it had when it was published, so nothing the journal holds about content created before it was
	 * shared is addressed to the one it was shared with afterwards. The step back the creation of a
	 * session takes is a different matter - there the band is handed over to the journal by a cursor put
	 * on the same boundary, while a walk over an area has no such compensation by construction: it
	 * reads no journal and moves no cursor.
	 */
	private function positionOf(StateCheckRequest $request, int $userId, string $appId): CheckToken
	{
		$raw = $request->getCheckToken();
		if ($raw === null)
		{
			$limits = new SyncLimits();
			$boundTimestamp = time() - SyncLimits::CLOCK_TOLERANCE_SECONDS;

			return CheckToken::start(
				$userId,
				$appId,
				DateTime::createFromTimestamp($boundTimestamp + self::tokenLifetime($limits)),
				(int)$request->getScopeRootId(),
				DateTime::createFromTimestamp($boundTimestamp),
			);
		}

		$token = CheckToken::decode($raw, $userId, $appId);
		$request->assertNamesTheAreaOf($token);

		return $token;
	}

	private static function tokenLifetime(SyncLimits $limits): int
	{
		return min(self::TOKEN_TTL_SECONDS, $limits->getJournalRetentionSeconds());
	}

	/**
	 * @param CheckVerdict[] $verdicts
	 */
	private function itemsOf(int $userId, array $verdicts, SectionsStructure $sections): DtoCollection
	{
		$cards = $this->cardsOf($userId, $verdicts, $sections);

		$items = new DtoCollection(CheckItemDto::class);
		foreach ($verdicts as $verdict)
		{
			$objectId = $verdict->getObjectId();

			$items->add(
				$verdict->isVisible()
					? CheckItemDto::visible($objectId, $cards[$objectId] ?? null)
					: CheckItemDto::invisible($objectId)
				,
			);
		}

		return $items;
	}

	/**
	 * One assembly for the whole page, out of the objects the verdicts declared visible and out of
	 * nothing else. A card per verdict would be a query per object, and a card for an invisible
	 * object would publish what the check exists not to publish.
	 *
	 * A page with nothing visible on it costs no assembly at all - which is the page the mode over an
	 * area answers with right after a revocation, its commonest one.
	 *
	 * @param CheckVerdict[] $verdicts
	 * @return array<int, ObjectCardDto>
	 */
	private function cardsOf(int $userId, array $verdicts, SectionsStructure $sections): array
	{
		$visibleIds = [];
		foreach ($verdicts as $verdict)
		{
			if ($verdict->isVisible())
			{
				$visibleIds[] = $verdict->getObjectId();
			}
		}

		if ($visibleIds === [])
		{
			return [];
		}

		// The storages of the reader are what a place is looked for inside, so they are asked for only
		// when a card of the page is going to name one. The answer costs a pass over the storages of the
		// whole portal and grows with their number, and every other section of a card is assembled
		// without it.
		$visibleStorageIds = $sections->has(SectionsStructure::SECTION_LOCATION)
			? array_keys((new StorageListProvider())->getVisibleStorages($userId))
			: []
		;

		$cards = [];
		foreach ((new CardCollector())->collect($userId, $visibleIds, $visibleStorageIds, $sections) as $card)
		{
			$cards[$card->objectId] = $card;
		}

		return $cards;
	}
}
