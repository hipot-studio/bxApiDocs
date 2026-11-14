<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Entity\Summary\SummarySection;
use CCrmOwnerType;

/**
 * Whom a deal is with: the type of the client, its identifier and its name, and nothing else. The
 * profile of a client is the subject of a summary of a client and has no place here.
 *
 * The link of a deal to its client answers an identifier and knows nothing of the rights, so the
 * right is applied by the read itself - by the selection that answers the client, the way
 * {@see DealSelector::selectAccessible()} reads the deals of one. A client this user may not read
 * stays out of the summary and the section is told restricted rather than empty: "the deal is with
 * nobody" and "the client is not this user's to see" are different facts, and a consumer that merges
 * them turns a missing right into an honest absence.
 *
 * @internal
 */
class DealClientReader
{
	/** The parts a name of a contact is put together of - the format of it belongs to the portal. */
	private const CONTACT_SELECT = [
		Item::FIELD_NAME_ID,
		Item::FIELD_NAME_HONORIFIC,
		Item::FIELD_NAME_NAME,
		Item::FIELD_NAME_SECOND_NAME,
		Item::FIELD_NAME_LAST_NAME,
	];

	private const COMPANY_SELECT = [
		Item::FIELD_NAME_ID,
		Item::FIELD_NAME_TITLE,
	];

	/**
	 * @param array $dealRow The row of {@see SummaryLimits::DEAL_SUMMARY_EXTRA_SELECT}.
	 */
	public function read(array $dealRow, int $userId): SummarySection
	{
		$client = self::clientOf($dealRow);
		if ($client === null)
		{
			return SummarySection::empty();
		}

		$item = $this->readAccessible($client, $userId);
		if ($item === null)
		{
			return SummarySection::restricted();
		}

		return SummarySection::filled([
			'entityType' => mb_strtolower(CCrmOwnerType::ResolveName($client->getEntityTypeId())),
			'entityId' => $client->getEntityId(),
			'name' => (string)$item->getHeading(),
		]);
	}

	/**
	 * The client as this user may see it, or nothing at all: the right to read is applied to the
	 * selection and the user is named to it, because the one the summary is collected for is not the
	 * one the process runs as.
	 *
	 * The fields asked for are the ones a name is made of - {@see Item::getHeading()} answers out of
	 * what the query brought back and loads nothing of its own.
	 *
	 * No limit is asked for, for the reason {@see ClientProfileReader::readItem()} gives: a strict
	 * filter by the primary key answers a single row anyway, while a limit of any size takes the read
	 * off its single-query path.
	 */
	protected function readAccessible(ItemIdentifier $client, int $userId): ?Item
	{
		$factory = Container::getInstance()->getFactory($client->getEntityTypeId());
		if ($factory === null)
		{
			return null;
		}

		$select = $client->getEntityTypeId() === CCrmOwnerType::Contact
			? self::CONTACT_SELECT
			: self::COMPANY_SELECT
		;

		return $factory->getItemsFilteredByPermissions(
			[
				'select' => $select,
				'filter' => ['=' . Item::FIELD_NAME_ID => $client->getEntityId()],
			],
			$userId,
			UserPermissions::OPERATION_READ,
		)[0] ?? null;
	}

	/**
	 * The client of the deal: the company of it, and the contact only when there is no company. That is
	 * the rule of the module for the primary client of an item
	 * ({@see \Bitrix\Crm\Field\IsReturnCustomer}), and the column the contact is read of is the primary
	 * one of the bindings - {@see \Bitrix\Crm\Binding\DealContact} keeps it so.
	 *
	 * Public because the rule has a second reader: a call bound to a deal is a summary of the client of
	 * that deal ({@see \Bitrix\Crm\V2\Internal\Integration\Voximplant\CallClientResolver}). It is
	 * the rule that is shared and not the read - the caller brings the row it already has.
	 *
	 * @param array $dealRow A row carrying `COMPANY_ID` and `CONTACT_ID`.
	 */
	public static function clientOf(array $dealRow): ?ItemIdentifier
	{
		$companyId = (int)($dealRow[Item::FIELD_NAME_COMPANY_ID] ?? 0);
		if ($companyId > 0)
		{
			return new ItemIdentifier(CCrmOwnerType::Company, $companyId);
		}

		$contactId = (int)($dealRow[Item::FIELD_NAME_CONTACT_ID] ?? 0);

		return $contactId > 0 ? new ItemIdentifier(CCrmOwnerType::Contact, $contactId) : null;
	}
}
