<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use CCrmOwnerType;

/**
 * Deals of a client, read in two steps: the whole history the user may see, and the details of the
 * freshest of them.
 *
 * The rights of the passed user are applied by the query of the first step, so the second one takes
 * identifiers that already passed them and checks nothing again.
 *
 * @internal
 */
class DealSelector
{
	/**
	 * Exactly the fields the aggregates read. A field left out of the select costs no read of its own
	 * later - {@see \Bitrix\Main\ORM\Objectify\EntityObject::sysGetValue()} answers `null` for it and
	 * loads nothing - which is worse than a query: the aggregates are then counted over deals worth
	 * nothing, of no currency and of no stage, and the summary tells those numbers as facts.
	 */
	private const NARROW_SELECT = [
		Item::FIELD_NAME_ID,
		Item::FIELD_NAME_CREATED_TIME,
		Item::FIELD_NAME_OPPORTUNITY,
		Item::FIELD_NAME_CURRENCY_ID,
		Item::FIELD_NAME_STAGE_SEMANTIC_ID,
		Item::FIELD_NAME_STAGE_ID,
	];

	/**
	 * @param int|null $limit Deals to read, the freshest first. The aggregates of a summary are counted
	 * over {@see SummaryLimits::AGGREGATE_CAP} of them and ask for no number of their own; a caller that
	 * names the deals instead of counting them asks for as many as it names, because every deal above
	 * that is loaded as a model and thrown away. A caller that counts them asks for one deal above the
	 * ceiling and no more ({@see SummaryLimits::PROBE_RECORD}): that deal is the signal that the history
	 * goes on below the selection, and the ceiling of the selector is raised by exactly it.
	 * @return Item[] The freshest first, at most the asked number of them.
	 * @throws ArgumentException When the client is neither a contact nor a company.
	 */
	public function selectAccessible(ItemIdentifier $client, int $userId, ?int $limit = null): array
	{
		$factory = $this->getDealFactory();
		if ($factory === null)
		{
			return [];
		}

		return $factory->getItemsFilteredByPermissions(
			[
				'select' => self::NARROW_SELECT,
				'filter' => self::buildClientFilter($client),
				'order' => [Item::FIELD_NAME_CREATED_TIME => 'DESC'],
				'limit' => self::limitOf($limit),
			],
			$userId,
			UserPermissions::OPERATION_READ,
		);
	}

	private static function limitOf(?int $limit): int
	{
		if ($limit === null || $limit <= 0)
		{
			return SummaryLimits::AGGREGATE_CAP;
		}

		return min($limit, SummaryLimits::AGGREGATE_CAP + SummaryLimits::PROBE_RECORD);
	}

	/**
	 * The texts of a deal are cut by the query that reads them: the summary keeps a thousand readable
	 * characters of any of them and the column holds up to sixty five thousand, so bringing the whole
	 * of it back only to throw it away is the cost of the biggest deals of a portal.
	 *
	 * @param int[] $dealIds Identifiers of the first step, already filtered by rights.
	 * @return Item[] In the order the identifiers were passed in - the freshest deal first.
	 */
	public function selectDetails(array $dealIds): array
	{
		$factory = $this->getDealFactory();
		if ($dealIds === [] || $factory === null)
		{
			return [];
		}

		$deals = $factory->getItems([
			'select' => self::detailSelect(),
			'runtime' => self::cutTextFields(),
			'filter' => ['@' . Item::FIELD_NAME_ID => $dealIds],
		]);

		return self::inTheOrderOf($dealIds, $deals);
	}

	/**
	 * The order of the first step, restored. A read by a list of identifiers is answered in whatever
	 * order the storage keeps the rows in - on MySQL that is the ascending primary key, the reverse of
	 * the freshest first the first step asked for - and the section is told to a model that reads it as
	 * a history. Reordering here costs no query and no `ORDER BY`, and leaves the rule of what "freshest"
	 * means in the one place that decides it.
	 *
	 * A deal deleted between the two steps is left out, and an identifier passed twice tells about its
	 * deal once.
	 *
	 * @param int[] $dealIds
	 * @param Item[] $deals
	 * @return Item[]
	 */
	private static function inTheOrderOf(array $dealIds, array $deals): array
	{
		$byId = [];
		foreach ($deals as $deal)
		{
			$byId[$deal->getId()] = $deal;
		}

		$ordered = [];
		foreach ($dealIds as $dealId)
		{
			if (isset($byId[$dealId]))
			{
				$ordered[] = $byId[$dealId];
				unset($byId[$dealId]);
			}
		}

		return $ordered;
	}

	/**
	 * The fields of the details, with every text among them asked for by the name its cut arrives under.
	 *
	 * @return string[]
	 */
	private static function detailSelect(): array
	{
		$select = [];
		foreach (SummaryLimits::DEAL_DETAIL_SELECT as $field)
		{
			$select[] = SummaryLimits::DEAL_TEXT_ALIASES[$field] ?? $field;
		}

		return $select;
	}

	/**
	 * @return ExpressionField[]
	 */
	private static function cutTextFields(): array
	{
		$fields = [];
		foreach (SummaryLimits::DEAL_TEXT_ALIASES as $field => $alias)
		{
			$fields[] = new ExpressionField(
				$alias,
				'SUBSTRING(%s, 1, ' . SummaryLimits::READ_TEXT_LENGTH . ')',
				$field,
			);
		}

		return $fields;
	}

	private static function buildClientFilter(ItemIdentifier $client): array
	{
		$filter = match ($client->getEntityTypeId())
		{
			CCrmOwnerType::Contact => [
				'=' . Item::FIELD_NAME_CONTACT_BINDINGS . '.CONTACT_ID' => $client->getEntityId(),
			],
			CCrmOwnerType::Company => [
				'=' . Item::FIELD_NAME_COMPANY_ID => $client->getEntityId(),
			],
			default => throw new ArgumentException('Only a contact or a company can be a client of a deal'),
		};

		// a recurring deal is a template of future ones and has no place in a history
		$filter['=' . Item::FIELD_NAME_IS_RECURRING] = 'N';

		return $filter;
	}

	private function getDealFactory(): ?Factory
	{
		return Container::getInstance()->getFactory(CCrmOwnerType::Deal);
	}
}
