<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Voximplant;

use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\Summary\DealClientReader;
use Bitrix\Main\Loader;
use Bitrix\Voximplant\Call;
use Bitrix\Voximplant\StatisticTable;
use CCrmOwnerType;

/**
 * Whom a call of the telephony is with, by the identifier of the call alone.
 *
 * The whole of the talk to the telephony lives here: the module is included by this class and nothing
 * of it leaves the class - the answer is a {@see ResolvedCall} or nothing at all. A portal without the
 * telephony has no calls, and that is an absent call rather than an error: the tab simply does not
 * appear.
 *
 * A call is looked for in two places, the way the controller of the neighbouring tab of the same panel
 * does it ({@see \Bitrix\Crm\Controller\Copilot\CallAssessment\CallCardPlacement::isCallExists()}):
 * first among the calls that are still going, then in the statistics. The second place is not a
 * fallback for rare data - when the other side hangs up, the row of the active call is dropped
 * together with its bindings and its participants, while the window of the call lives on, and it is
 * exactly those seconds a summary launched at the end of the talk reports back in.
 *
 * The class is left open to inheritance for the tests of its callers alone: the controller of the tab
 * is measured without a portal that has the telephony installed.
 *
 * Rights are not asked about here. Whether this user may read the client is the question of the
 * controller: answered here, "the call is with nobody" and "the client is not yours to see" would
 * become one answer.
 *
 * @internal
 */
class CallClientResolver
{
	/**
	 * The ceiling of the statistics rows one call is read of. A talk has as many rows as it had people
	 * on the line, and a conversation of that size does not happen; the ceiling is here so that a row
	 * count nobody controls cannot turn into a selection of any size.
	 */
	public const MAX_STATISTIC_ROWS = 50;

	public function resolve(string $callId): ?ResolvedCall
	{
		if ($callId === '' || !Loader::includeModule('voximplant'))
		{
			return null;
		}

		return $this->resolveActiveCall($callId) ?? $this->resolveFinishedCall($callId);
	}

	/**
	 * A call that is still going knows its participants itself, and its primary binding is the one the
	 * card of the call is drawn by.
	 */
	private function resolveActiveCall(string $callId): ?ResolvedCall
	{
		$call = Call::load($callId);
		if (!($call instanceof Call))
		{
			return null;
		}

		$client = $this->clientOf(
			(string)$call->getPrimaryEntityType(),
			(int)$call->getPrimaryEntityId(),
		);

		$participantIds = array_map(static fn($userId): int => (int)$userId, $call->getUserIds());

		return new ResolvedCall($client, $this->cleanParticipantIds($participantIds));
	}

	/**
	 * A finished call is read of every statistics row of that identifier, and not of one.
	 *
	 * In the statistics a participant is a single column of the row ({@see StatisticTable}
	 * `PORTAL_USER_ID`), the key by the identifier of the call is not unique, and a call that was
	 * forwarded leaves a row per person who picked it up. So the selection is made here instead of
	 * taking the ready {@see StatisticTable::getByCallId()}, which ends in `fetch()` and answers one
	 * arbitrary row: bound to it, the tab would disappear for the person who really was in the call but
	 * whose row happened not to be first.
	 *
	 * The client is read of the first row that has a binding to CRM at all - the rows of one call
	 * describe one talk, and the rows that were written before the call was bound carry no binding.
	 * That row settles the question whatever it answers: a deal of no client answers `null`, and the
	 * rows past it name the same deal.
	 *
	 * The rows are taken oldest first and no more than {@see self::MAX_STATISTIC_ROWS} of them.
	 */
	private function resolveFinishedCall(string $callId): ?ResolvedCall
	{
		$rows = StatisticTable::getList([
			'select' => ['ID', 'PORTAL_USER_ID', 'CRM_ENTITY_TYPE', 'CRM_ENTITY_ID'],
			'filter' => ['=CALL_ID' => $callId],
			'order' => ['ID' => 'ASC'],
			'limit' => self::MAX_STATISTIC_ROWS,
		])->fetchAll();

		if ($rows === [])
		{
			return null;
		}

		$client = null;
		$bindingSeen = false;
		$participantIds = [];
		foreach ($rows as $row)
		{
			$participantIds[] = (int)($row['PORTAL_USER_ID'] ?? 0);

			$entityTypeName = (string)($row['CRM_ENTITY_TYPE'] ?? '');
			$entityId = (int)($row['CRM_ENTITY_ID'] ?? 0);

			if ($bindingSeen || $entityTypeName === '' || $entityId <= 0)
			{
				continue;
			}

			// the answer of the first bound row is the answer of the call, `null` included: the rows
			// describe one talk, so the rows past it name the same entity and reading it again reads
			// the same deal of the same call once per row
			$bindingSeen = true;
			$client = $this->clientOf($entityTypeName, $entityId);
		}

		return new ResolvedCall($client, $this->cleanParticipantIds($participantIds));
	}

	/**
	 * The client of the entity the call is bound to: a contact and a company are the client themselves,
	 * a deal is the client of it, and a lead has no summary in any shape.
	 */
	private function clientOf(string $entityTypeName, int $entityId): ?ItemIdentifier
	{
		if ($entityTypeName === '' || $entityId <= 0)
		{
			return null;
		}

		$entityTypeId = CCrmOwnerType::ResolveID($entityTypeName);

		return match ($entityTypeId)
		{
			CCrmOwnerType::Contact, CCrmOwnerType::Company => new ItemIdentifier($entityTypeId, $entityId),
			CCrmOwnerType::Deal => $this->clientOfDeal($entityId),
			default => null,
		};
	}

	/**
	 * The rule of the client of a deal is not written here a second time: the row is read and handed to
	 * the one place that owns the rule ({@see DealClientReader::clientOf()}).
	 */
	private function clientOfDeal(int $dealId): ?ItemIdentifier
	{
		$factory = Container::getInstance()->getFactory(CCrmOwnerType::Deal);
		if ($factory === null)
		{
			return null;
		}

		$dataClass = $factory->getDataClass();
		$dealRow = $dataClass::getRow([
			'select' => [
				Item::FIELD_NAME_ID,
				Item::FIELD_NAME_COMPANY_ID,
				Item::FIELD_NAME_CONTACT_ID,
			],
			'filter' => ['=' . Item::FIELD_NAME_ID => $dealId],
		]);

		return $dealRow === null ? null : DealClientReader::clientOf($dealRow);
	}

	/**
	 * @param int[] $participantIds
	 * @return int[]
	 */
	private function cleanParticipantIds(array $participantIds): array
	{
		return array_values(array_unique(array_filter($participantIds, static fn(int $id): bool => $id > 0)));
	}
}
