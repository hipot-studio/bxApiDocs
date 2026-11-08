<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\EntityPreset;
use Bitrix\Crm\V2\Internal\Entity\Requisite\Preset;
use Bitrix\Crm\V2\Internal\Repository\Requisite\PresetRepository;
use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteRepository;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\SystemException;

/**
 * The questions asked about the preset of a requisite: is the preset there, is it one the portal offers,
 * what does it declare, and which of the ones the portal offers is the default one of an owner type.
 *
 * The first two are not the same question, and answering them together would be wrong in both directions.
 * A preset the portal switched off is there: requisites name it, the write of the facade keeps writing
 * them, and legacy checks nothing at all - a requisite may name a preset that was deleted years ago
 * (there is no foreign key). But it is not a preset a new requisite may be created against, and it is not
 * a preset whose composition a client was offered a choice from. So the line with the checks asks for a
 * preset the portal offers, and the line without them asks only whether one is there.
 *
 * The third question belongs to the preset itself, {@see Preset::hasField()}.
 *
 * The fourth is the rule of the card of a requisite, and it is a rule about an **owner type** rather than
 * about a preset: the portal-wide setting first, and a preset shipped with the product for the country of
 * the portal and the kind of person that owner type stands for when the setting says nothing
 * ({@see self::resolveDefaultPresetId()}). The other mechanism that looks like it -
 * {@see EntityPreset::getDefault()}, the first active preset by sort - knows nothing of owners and is not
 * the same rule: on a portal where an administrator has chosen a preset of its own, the two name
 * different presets.
 *
 * Only reads. Legacy answers the fourth question by writing a portal-wide option as a side effect of
 * reading ({@see \Bitrix\Crm\EntityRequisite::getDefaultPresetId()}); nothing here does that, and nothing
 * needs it to - the card of a requisite is what fills that setting in.
 *
 * @internal
 */
final class PresetResolver
{
	/**
	 * The portal-wide setting the card of a requisite fills in: a map of the name of an owner type to
	 * the preset an administrator chose for it.
	 */
	private const DEFAULT_PRESET_OPTION = 'requisite_default_presets';

	/**
	 * The names the setting is keyed by. They are the names legacy resolves an owner type to
	 * ({@see \CCrmOwnerType::ResolveName()}), and the setting is written by legacy, so the two lists
	 * cannot be allowed to disagree.
	 */
	private const OWNER_TYPE_NAME = [
		OwnerType::CONTACT => OwnerType::CONTACT_NAME,
		OwnerType::COMPANY => OwnerType::COMPANY_NAME,
	];

	/** The external code of a preset shipped with the product, by country and kind of person. */
	private const DEFAULT_PRESET_XML_ID = '#CRM_REQUISITE_PRESET_DEF_%s_%s#';

	private const PERSON_KIND_CONTACT = 'PERSON';

	private const PERSON_KIND_COMPANY = 'LEGALENTITY';

	private const PERSON_KIND_COMPANY_RU = 'COMPANY';

	private const COUNTRY_CODE_RU = 'RU';

	public function __construct(private readonly PresetRepository $presets = new PresetRepository())
	{
	}

	/**
	 * The preset $presetId names, and `null` when there is none - including for an identifier that could
	 * not be one, so a caller never has to check the identifier before asking.
	 *
	 * @throws SystemException a failure of the storage.
	 */
	public function find(int $presetId): ?Preset
	{
		return $this->presets->findById($presetId);
	}

	/**
	 * The preset $presetId names among the ones the portal offers, and `null` when it is not one of them -
	 * whether because there is no such preset or because the portal switched it off.
	 *
	 * The two ways of asking cannot disagree: being offered by the portal is being a preset of a requisite
	 * that is active, and that is the whole of what {@see PresetRepository::findActiveOfPortal()} selects
	 * by as well.
	 *
	 * @throws SystemException a failure of the storage.
	 */
	public function findAvailable(int $presetId): ?Preset
	{
		$preset = $this->find($presetId);

		return $preset !== null && $preset->active ? $preset : null;
	}

	/**
	 * The presets the portal offers, ordered as asked, each of them answering whether it is the default
	 * one of $ownerTypeId.
	 *
	 * The composition is the same one for both owner types a requisite belongs to - only the mark
	 * differs. That is why this is one read with an argument rather than two reads of a name each: two
	 * would be the same query twice and would drift the moment one of them learned something.
	 *
	 * The mark is only ever raised on a preset of this answer. A portal may keep naming as its default a
	 * preset that has since been switched off or deleted - the setting is never cleaned up and legacy
	 * checks neither - and then no preset of the answer carries the mark. The alternative is to point a
	 * client at a preset it was not offered and cannot choose.
	 *
	 * @param array<string, string> $order field name => `ASC`/`DESC`, carried down to the query rather
	 *        than applied to the answer: the mark is attached to rows that were already chosen and
	 *        ordered. An empty ordering is the order the interface shows the presets in.
	 * @return Preset[]
	 * @throws ArgumentException an owner type a requisite does not belong to, or an ordering over a field
	 *         the contract of a preset does not declare sortable.
	 * @throws SystemException a failure of the storage.
	 */
	public function listPresets(int $ownerTypeId, array $order = []): array
	{
		$defaultPresetId = $this->resolveDefaultPresetId($ownerTypeId);

		$presets = [];
		foreach ($this->presets->findActiveOfPortal($order) as $preset)
		{
			$presets[] = $preset->withIsDefault($defaultPresetId > 0 && $preset->id === $defaultPresetId);
		}

		return $presets;
	}

	/**
	 * The preset the portal treats as the default one of $ownerTypeId, and `0` when it names none.
	 *
	 * The rule is the one of the card of a requisite, kept as it works rather than as it ought to: the
	 * setting of the portal first, and otherwise the preset shipped with the product for the country of
	 * the portal and the kind of person this owner type stands for. Neither branch checks that the
	 * preset it names is there or that the portal offers it - a check legacy does not make would make
	 * this answer a different preset than the card calls its default one. What to do about a preset that
	 * is not offered belongs to {@see self::listPresets()}.
	 *
	 * @throws ArgumentException an owner type a requisite does not belong to: the rule is not defined
	 *         for one, and a zero would read as a portal that names no default preset at all.
	 * @throws SystemException a failure of the storage.
	 */
	public function resolveDefaultPresetId(int $ownerTypeId): int
	{
		if (!in_array($ownerTypeId, RequisiteRepository::OWNER_TYPE_IDS, true))
		{
			throw new ArgumentException('Requisites do not belong to owners of this type', 'ownerTypeId');
		}

		$chosenByPortal = $this->defaultPresetIdFromOption($ownerTypeId);

		return $chosenByPortal > 0
			? $chosenByPortal
			: $this->presets->findIdByXmlId($this->shippedDefaultPresetXmlId($ownerTypeId))
		;
	}

	/**
	 * The preset an administrator chose for this owner type, and `0` when none was chosen or the setting
	 * holds something a map of owner types is not.
	 */
	private function defaultPresetIdFromOption(int $ownerTypeId): int
	{
		$stored = Option::get('crm', self::DEFAULT_PRESET_OPTION);
		$chosenByOwnerType = $stored === '' ? null : unserialize($stored, ['allowed_classes' => false]);
		if (!is_array($chosenByOwnerType))
		{
			return 0;
		}

		$presetId = (int)($chosenByOwnerType[self::OWNER_TYPE_NAME[$ownerTypeId]] ?? 0);

		return max($presetId, 0);
	}

	/**
	 * The external code of the preset the product ships as the default one of this owner type on this
	 * portal.
	 *
	 * The country of the portal is read the way the card reads it ({@see EntityPreset}): it is a setting
	 * of the module translated through the country list of the kernel, and asking it anywhere else would
	 * risk naming a preset the card does not.
	 */
	private function shippedDefaultPresetXmlId(int $ownerTypeId): string
	{
		$countryCode = EntityPreset::getCountryCodeById(EntityPreset::getCurrentCountryId());

		return sprintf(self::DEFAULT_PRESET_XML_ID, $countryCode, self::personKind($ownerTypeId, $countryCode));
	}

	/**
	 * The kind of person an owner type stands for, which is what the shipped presets are named after. A
	 * company is one kind on a RU portal and another everywhere else; a contact is a person wherever it
	 * is kept.
	 */
	private static function personKind(int $ownerTypeId, string $countryCode): string
	{
		if ($ownerTypeId !== OwnerType::COMPANY)
		{
			return self::PERSON_KIND_CONTACT;
		}

		return $countryCode === self::COUNTRY_CODE_RU
			? self::PERSON_KIND_COMPANY_RU
			: self::PERSON_KIND_COMPANY
		;
	}
}
