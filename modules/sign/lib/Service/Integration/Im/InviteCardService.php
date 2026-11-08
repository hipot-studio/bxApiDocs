<?php

namespace Bitrix\Sign\Service\Integration\Im;

use Bitrix\Im\Model\MessageParamTable;
use Bitrix\Im\V2\Message as ChatMessage;
use Bitrix\Im\V2\Message\Param\ComponentParams;
use Bitrix\Im\V2\Message\Param\PushService;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Main\Loader;
use Bitrix\Sign\Contract\Chat\Message\HasMemberKey;
use Bitrix\Sign\Item;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\DocumentStatus;
use Bitrix\Sign\Type\MemberStatus;
use Throwable;

/**
 * Keeps the look of an already sent invitation card in step with the participant's position: a card
 * whose action is no longer expected is marked completed, and the clients draw it muted.
 *
 * Muting is the only direction. A participant cannot come back from a finished status without being
 * sent a fresh card along the way, so a card once quiet stays quiet, and the flag is set rather than
 * written from a predicate. That also makes every call here idempotent and free of any order between
 * the domain changes it follows.
 *
 * Everything here is subordinate to signing. Methods run after the domain change is committed, never
 * throw and never report errors to the caller: a card left blue is a cosmetic defect, a signing that
 * fails because of a card is not.
 */
class InviteCardService
{
	/**
	 * Completion flag inside COMPONENT_PARAMS. Reaches the clients as `actionCompleted`: the
	 * component block is run through the camelCase converter on its way out.
	 */
	public const ACTION_COMPLETED_KEY = 'ACTION_COMPLETED';

	/**
	 * Mutes the cards of one participant. Whether the action is over is the caller's to decide: it holds
	 * the participant and the document at the moment of the change and can tell the change that ends an
	 * action from the steps that lead up to it.
	 */
	public function markCompletedForMember(Item\Member $member): void
	{
		if ($member->id === null)
		{
			return;
		}

		$this->apply([$member->id]);
	}

	/**
	 * Mutes the cards of a document that ended. Stopping does not move participants to a finished status
	 * by itself, and a completion is the last chance the document has: past it a participant's own
	 * status change ends nothing any more and carries no marking of its own.
	 *
	 * Every participant gets the flag, the ones already carrying a finished status included: a status
	 * can reach a finished value past this service - the callback writes some of them directly - and a
	 * card whose muting was missed once would otherwise stay active for good. A card that already
	 * carries the value is left alone anyway, so the wider set costs the lookup and nothing else.
	 *
	 * The participants come from the caller: the operation this runs in has the list loaded for its own
	 * work, and asking for it a second time would cost a full read per document on a batch stop.
	 */
	public function markCompletedForMembers(Item\MemberCollection $members): void
	{
		$memberIds = [];
		foreach ($members as $member)
		{
			if ($member->id !== null)
			{
				$memberIds[] = $member->id;
			}
		}

		if ($memberIds === [])
		{
			return;
		}

		$this->apply($memberIds);
	}

	/**
	 * Whether the participant has nothing left to do. Public because a card has to be born in this
	 * state too: the invitation is sent from elsewhere, and one that leaves after the participant
	 * finished or the document ended is never swept afterwards.
	 *
	 * The document has the last word. Its own path allows a participant status to arrive after the
	 * document is over - the callback reaches the status change of a stopped or completed document -
	 * and a late `waiting` or `ready` would otherwise show an action nobody can perform any more.
	 */
	public static function isActionOver(Item\Member $member, ?Item\Document $document): bool
	{
		return ($document !== null && DocumentStatus::isFinalByDocument($document))
			|| MemberStatus::isFinishForSigning($member->status)
		;
	}

	/**
	 * @param list<int> $memberIds member records whose cards are to be muted
	 */
	private function apply(array $memberIds): void
	{
		try
		{
			// The one thing worth asking before reaching for the chat: without the module its classes
			// do not load at all, so a portal that has no chat would put a journal record on every
			// participant of every document instead of a silent skip. Which version of the module
			// answered is deliberately not asked - a chat older than this code fails on the symbol it
			// is missing, and the catch below turns that into a card left as it is.
			if (!Loader::includeModule('im'))
			{
				return;
			}

			$cardIds = $this->findCards($memberIds);

			// No cards is a normal outcome: the card was deleted, the chat was cleared, the invitation
			// was never sent, or the card predates the release and carries no key.
			if ($cardIds === [])
			{
				return;
			}

			$cards = (new MessageCollection($cardIds))->fillParams();
		}
		catch (Throwable $exception)
		{
			$this->logFailure('members', implode(',', $memberIds), $exception->getMessage());

			return;
		}

		foreach ($cards as $card)
		{
			try
			{
				$this->applyToCard($card);
			}
			catch (Throwable $exception)
			{
				// Caught per card on purpose: one card the chat cannot take back must not cost the
				// participant the rest of theirs, and a repeat invitation gives them many.
				$this->logFailure('card', (int)$card->getMessageId(), $exception->getMessage());
			}
		}
	}

	/**
	 * Reads the chat's parameter table directly: im has no V2 way to look messages up by a parameter
	 * value, and its own code reads this table the same way. Message\ParamArray::find() cannot serve
	 * here - it holds one multi-valued parameter of one message, and loading it stamps the message id
	 * of its first row onto every element, so every card but the first would be lost.
	 *
	 * @param list<int> $memberIds
	 * @return list<int> ids of the messages carrying the cards of these participants
	 */
	private function findCards(array $memberIds): array
	{
		$rows = MessageParamTable::query()
			->setSelect(['MESSAGE_ID'])
			->where('PARAM_NAME', HasMemberKey::MEMBER_KEY_PARAM)
			->whereIn('PARAM_VALUE', array_map(static fn (int $id): string => (string)$id, $memberIds))
			->fetchAll()
		;

		return array_values(array_unique(array_map(
			static fn (array $row): int => (int)$row['MESSAGE_ID'],
			$rows,
		)));
	}

	private function applyToCard(ChatMessage $card): void
	{
		$params = $card->getParams();

		// The key alone does not prove the message is a card of ours: the parameter name is not
		// reserved by anything, so the component that draws the message has to agree as well.
		if ($params->get(Params::COMPONENT_ID)->getValue() !== ImService::IM_COMPONENT_ID)
		{
			return;
		}

		$componentParams = $params->get(Params::COMPONENT_PARAMS);
		if (!$componentParams instanceof ComponentParams)
		{
			return;
		}

		$block = $componentParams->getValue();
		if (!is_array($block))
		{
			return;
		}

		if ((bool)($block[self::ACTION_COMPLETED_KEY] ?? false))
		{
			return;
		}

		// The block is rewritten whole: writing just our own field would drop the stage, the document
		// and the rest of the card context.
		$block[self::ACTION_COMPLETED_KEY] = true;
		$componentParams->setValue($block);

		// Not required: Params::save() runs prepareFields() first, and that marks the parameter itself
		// once the ORM entity sees the new json. Kept so the write does not depend on that detail of
		// the chat module. Do not reach for setJsonValue() instead - it marks the parameter but leaves
		// the decoded value stale, and the push below sends that value, so the database would get the
		// new block and every open chat the old one.
		$componentParams->markChanged();

		$saveResult = $params->save();
		if (!$saveResult->isSuccess())
		{
			// The one failure that carries no exception, and so the one that would otherwise leave the
			// card blue with nothing in the journal to find it by.
			$this->logFailure('card', (int)$card->getMessageId(), implode('; ', $saveResult->getErrorMessages()));

			return;
		}

		(new PushService())->sendPull($card, [Params::COMPONENT_PARAMS]);
	}

	private function logFailure(string $entity, int|string $id, string $reason): void
	{
		Container::instance()->getLogger('Service')->error(
			'Failed to repaint sign invitation cards for {entity} {id}: {errorsText}',
			[
				'entity' => $entity,
				'id' => $id,
				'errorsText' => $reason,
			],
		);
	}
}
