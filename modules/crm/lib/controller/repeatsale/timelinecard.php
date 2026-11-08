<?php

namespace Bitrix\Crm\Controller\RepeatSale;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\Activity\RepeatSale;
use Bitrix\Main\Error;
use CCrmActivity;
use CCrmOwnerType;

/**
 * Purchase history of the repeat sale card, read for whoever asks.
 *
 * The card is also delivered by a pull event, and one pull payload reaches every subscriber of the deal
 * while the history can only be read with the rights of one user. So the pushed card carries no history
 * and the client asks for it here, in a request of its own - the only place where the recipient of the
 * card and the user of the request are the same person.
 */
final class TimelineCard extends Base
{
	public function getPurchaseHistoryAction(int $activityId): ?array
	{
		if ($activityId <= 0)
		{
			$this->addError(new Error('Unknown activity'));

			return null;
		}

		$activity = CCrmActivity::GetByID($activityId, false);
		if (!is_array($activity))
		{
			$this->addError(ErrorCode::getNotFoundError());

			return null;
		}

		$ownerTypeId = (int)($activity['OWNER_TYPE_ID'] ?? 0);
		$ownerId = (int)($activity['OWNER_ID'] ?? 0);
		if ($ownerTypeId !== CCrmOwnerType::Deal || $ownerId <= 0)
		{
			$this->addError(ErrorCode::getNotFoundError());

			return null;
		}

		if (!Container::getInstance()->getUserPermissions()->checkReadPermissions($ownerTypeId, $ownerId))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$card = $this->createCard($activity, $ownerTypeId, $ownerId);
		if ($card === null)
		{
			$this->addError(ErrorCode::getNotFoundError());

			return null;
		}

		return $this->extractHistory($card);
	}

	/**
	 * The card as the timeline of the current user builds it: the history read inside it goes by the user
	 * of this context, so it holds nothing this user may not read.
	 */
	private function createCard(array $activity, int $ownerTypeId, int $ownerId): ?RepeatSale
	{
		$context = new Context(
			new ItemIdentifier($ownerTypeId, $ownerId),
			Context::DESKTOP,
			Container::getInstance()->getContext()->getUserId(),
		);

		$item = Container::getInstance()->getTimelineScheduledItemFactory()::createItem($context, $activity);

		return $item instanceof RepeatSale ? $item : null;
	}

	/**
	 * The blocks the card built for itself. Asking the card to build them again would read the client's
	 * deals and their products a second time within one request.
	 *
	 * @return array{history: array, historyTotalHint: string}
	 */
	private function extractHistory(RepeatSale $card): array
	{
		$body = $card->getLayout()->getBody()?->getContentBlocks()['repeatSaleBody'] ?? null;
		$properties = $body?->toArray()['properties'] ?? [];

		return [
			'history' => $properties['history'] ?? [],
			'historyTotalHint' => (string)($properties['historyTotalHint'] ?? ''),
		];
	}
}
