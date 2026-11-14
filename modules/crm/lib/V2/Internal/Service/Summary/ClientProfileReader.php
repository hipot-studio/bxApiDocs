<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

use Bitrix\Crm\Format\Money;
use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\DataCollector\ClientDataCollector;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\StatusTable;
use Bitrix\Crm\V2\Internal\Entity\Summary\SummarySection;
use Bitrix\Crm\V2\Internal\Entity\Summary\TruncationReason;
use Bitrix\Main\ORM\Fields\ExpressionField;
use CCrmContentType;
use CCrmOwnerType;

/**
 * Profile of a client: who they are, when they appeared and when they were last talked to.
 *
 * It is read twice on purpose. {@see ClientDataCollector} answers the dates - whether the last
 * communication is available at all is decided inside it - but its select is fixed in its own code,
 * so the remaining fields are read by a query of our own.
 *
 * The name is not taken from it. The collector reads `NAME` of a contact alone, so a person entered
 * under a surname only would be named nowhere in the summary, and the summary of a deal names the
 * very same person in the format of the portal ({@see DealClientReader}). Both now ask
 * {@see Item::getHeading()} of the row that is read here anyway.
 *
 * The section is never restricted: the right to read the client is the gate of the whole summary,
 * and a client the user may not read has no summary to put a section into.
 *
 * @internal
 */
class ClientProfileReader
{
	/** The parts a name of a contact is put together of - the format of it belongs to the portal. */
	private const CONTACT_SELECT = [
		Item::FIELD_NAME_ID,
		Item::FIELD_NAME_HONORIFIC,
		Item::FIELD_NAME_NAME,
		Item::FIELD_NAME_SECOND_NAME,
		Item::FIELD_NAME_LAST_NAME,
		Item::FIELD_NAME_ASSIGNED,
		Item::FIELD_NAME_POST,
		Item::FIELD_NAME_HAS_PHONE,
		Item::FIELD_NAME_HAS_EMAIL,
		Item::FIELD_NAME_HAS_IMOL,
		SummaryLimits::PROFILE_COMMENT_ALIAS,
	];

	private const COMPANY_SELECT = [
		Item::FIELD_NAME_ID,
		Item::FIELD_NAME_TITLE,
		Item::FIELD_NAME_ASSIGNED,
		Item\Company::FIELD_NAME_INDUSTRY,
		Item\Company::FIELD_NAME_EMPLOYEES,
		Item\Company::FIELD_NAME_REVENUE,
		Item::FIELD_NAME_CURRENCY_ID,
		Item::FIELD_NAME_HAS_PHONE,
		Item::FIELD_NAME_HAS_EMAIL,
		Item::FIELD_NAME_HAS_IMOL,
		SummaryLimits::PROFILE_COMMENT_ALIAS,
	];

	public function read(ItemIdentifier $client): SummarySection
	{
		$markers = $this->readMarkers($client);
		if ($markers === [])
		{
			return SummarySection::empty();
		}

		$profile = self::lastTouches($markers);
		$isCommentTruncated = false;

		$item = $this->readItem($client);
		if ($item !== null)
		{
			$profile = array_merge(self::nameOf($item), $profile, $this->fieldsOf($item));

			[$comments, $isCommentTruncated] = self::commentOf($item);
			if ($comments !== '')
			{
				$profile['comments'] = $comments;
			}
		}

		if ($profile === [])
		{
			return SummarySection::empty();
		}

		return SummarySection::filled(
			$profile,
			$isCommentTruncated ? TruncationReason::LengthLimit : null,
		);
	}

	/**
	 * @return array Empty when there is no such client.
	 */
	protected function readMarkers(ItemIdentifier $client): array
	{
		return (new ClientDataCollector($client->getEntityTypeId()))
			->getMarkers(['entityId' => $client->getEntityId()])
		;
	}

	/**
	 * The right to the client has been checked by the caller, so the query asks for no rights of its
	 * own and reads exactly the fields the profile is made of.
	 *
	 * The comment on a client is cut by the query itself: the profile keeps
	 * {@see SummaryLimits::PROFILE_COMMENT_LENGTH} readable characters of it and the column holds up to
	 * sixty five thousand. A query cannot answer the same field twice, so the cut arrives under a name
	 * of its own.
	 *
	 * No limit is asked for, and that is what makes it one query: a strict filter by the primary key
	 * answers a single row anyway, while a limit of any size takes
	 * {@see \Bitrix\Crm\Service\Factory::getItems()} off its single-query path and has it read the
	 * identifiers before the row.
	 */
	protected function readItem(ItemIdentifier $client): ?Item
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

		return $factory->getItems([
			'select' => $select,
			'runtime' => [
				new ExpressionField(
					SummaryLimits::PROFILE_COMMENT_ALIAS,
					'SUBSTRING(%s, 1, ' . SummaryLimits::READ_TEXT_LENGTH . ')',
					Item::FIELD_NAME_COMMENTS,
				),
			],
			'filter' => ['=' . Item::FIELD_NAME_ID => $client->getEntityId()],
		])[0] ?? null;
	}

	protected function dictionaryName(string $dictionaryId, string $valueId): string
	{
		if ($valueId === '')
		{
			return '';
		}

		return (string)(StatusTable::getStatusesList($dictionaryId)[$valueId] ?? '');
	}

	/**
	 * The comment of the client as the profile carries it, and whether anything of it was lost.
	 *
	 * Two things can cut it: the query, which reads at most {@see SummaryLimits::READ_TEXT_LENGTH}
	 * characters of the column, and the profile itself, which keeps
	 * {@see SummaryLimits::PROFILE_COMMENT_LENGTH} readable ones. The first is counted on the text as it
	 * arrived and not on the readable remainder of it - markup that ate almost the whole of a long
	 * comment leaves a remainder well inside the limit of the profile, and the section would call itself
	 * whole while the client had said more.
	 *
	 * @return array{0: string, 1: bool} The comment and whether it is cut.
	 */
	private static function commentOf(Item $item): array
	{
		$stored = (string)$item->get(SummaryLimits::PROFILE_COMMENT_ALIAS);
		$comment = self::plainText($stored);

		return [
			mb_substr($comment, 0, SummaryLimits::PROFILE_COMMENT_LENGTH),
			mb_strlen($comment) > SummaryLimits::PROFILE_COMMENT_LENGTH
				|| mb_strlen($stored) >= SummaryLimits::READ_TEXT_LENGTH
			,
		];
	}

	private static function lastTouches(array $markers): array
	{
		return self::withoutMissing([
			'createdAt' => $markers['created_date'] ?? null,
			'lastInteractionAt' => $markers['last_interaction_date'] ?? null,
		]);
	}

	/**
	 * The name the portal gives this client, out of the fields the read already brought back:
	 * {@see Item::getHeading()} loads nothing of its own.
	 */
	private static function nameOf(Item $item): array
	{
		return self::withoutMissing(['name' => (string)$item->getHeading()]);
	}

	private function fieldsOf(Item $item): array
	{
		$own = $item->getEntityTypeId() === CCrmOwnerType::Contact
			? $this->contactFieldsOf($item)
			: $this->companyFieldsOf($item)
		;

		return self::withoutMissing(array_merge(
			['assignedById' => self::identifier($item->get(Item::FIELD_NAME_ASSIGNED))],
			$own,
			[
				'hasPhone' => self::yesNo($item->get(Item::FIELD_NAME_HAS_PHONE)),
				'hasEmail' => self::yesNo($item->get(Item::FIELD_NAME_HAS_EMAIL)),
				'hasMessenger' => self::yesNo($item->get(Item::FIELD_NAME_HAS_IMOL)),
			],
		));
	}

	/**
	 * The companies bound to the contact are left out on purpose. The gate of the summary is the right
	 * to the client alone, so the bindings would name companies this user may not read, and bare
	 * identifiers of them are nothing a summary is written out of: the blocks ask for the trade of the
	 * client and for who decides, and an identifier answers neither.
	 */
	private function contactFieldsOf(Item $item): array
	{
		return ['post' => trim((string)$item->get(Item::FIELD_NAME_POST))];
	}

	private function companyFieldsOf(Item $item): array
	{
		$revenue = (float)$item->get(Item\Company::FIELD_NAME_REVENUE);

		return [
			'industry' => $this->dictionaryName(
				StatusTable::ENTITY_ID_INDUSTRY,
				(string)$item->get(Item\Company::FIELD_NAME_INDUSTRY),
			),
			'employees' => $this->dictionaryName(
				StatusTable::ENTITY_ID_EMPLOYEES,
				(string)$item->get(Item\Company::FIELD_NAME_EMPLOYEES),
			),
			'revenue' => $revenue > 0
				? Money::format($revenue, (string)$item->get(Item::FIELD_NAME_CURRENCY_ID))
				: ''
			,
		];
	}

	/**
	 * A field the client has no value for says nothing and is left out entirely: an empty string in
	 * the summary reads to the model as an answer that was looked up and found blank.
	 */
	private static function withoutMissing(array $fields): array
	{
		return array_filter(
			$fields,
			static fn ($value): bool => $value !== null && $value !== '' && $value !== [],
		);
	}

	private static function identifier(mixed $value): ?int
	{
		return (int)$value > 0 ? (int)$value : null;
	}

	private static function yesNo(mixed $flag): string
	{
		return ($flag === true || $flag === 'Y') ? 'yes' : 'no';
	}

	/**
	 * The same shape {@see \Bitrix\Crm\RepeatSale\DataCollector\Mapper\AbstractFieldsMapper} gives a
	 * deal. Its own normalizer is not reusable: the mapper answers deals only and keeps the method
	 * final and protected.
	 */
	private static function plainText(mixed $text): string
	{
		$plain = TextHelper::cleanTextByType((string)$text, CCrmContentType::BBCode);

		return trim(str_replace('&nbsp;', '', $plain));
	}
}
