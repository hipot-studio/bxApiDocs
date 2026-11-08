<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Requisite;

/**
 * A preset of a requisite - the choice of a jurisdiction, made once and answered for by every requisite
 * that names it.
 *
 * The storage keeps a cell for every legal detail the product knows, and a requisite fills only the few
 * its preset shows ({@see Requisite}). So a preset is what decides which fields a requisite means
 * anything by, what the interface calls them and in what order it shows them - and the composition below
 * is that decision, as the portal configured it.
 *
 * `active` is the whole of what makes a preset one the portal offers: a preset the portal switched off is
 * still there, still named by the requisites created on it, and still not something a new requisite may
 * be created against. Which of the two questions is being asked is
 * {@see \Bitrix\Crm\V2\Internal\Service\Requisite\PresetResolver}.
 *
 * Read-only by construction - {@see \Bitrix\Crm\V2\Internal\Repository\Requisite\PresetRepository} is the
 * only place that reads one out of the storage, and the one mark a preset carries that the storage does
 * not keep is attached through {@see self::withIsDefault()}.
 *
 * @internal
 */
final readonly class Preset
{
	/**
	 * The entries of the composition that name a field of the contract, by that name.
	 *
	 * @var array<string, true>
	 */
	private array $declaredFields;

	/**
	 * @param string $name the name of the preset as the portal gave it, not a name of the product.
	 * @param bool $active whether the portal offers the preset for new requisites.
	 * @param int $countryId the country of the preset. It does not narrow the presets the portal offers -
	 *        the filter that would do that has been unreachable for years ({@see \Bitrix\Crm\EntityPreset::isUTFMode()}),
	 *        so the composition of presets is the same one the card of a requisite shows.
	 * @param PresetField[] $fields the composition, in the order the interface shows it: by the sort of
	 *        the entry, then by its identifier.
	 * @param bool|null $isDefault whether the preset is the default one **of a type of owner**, and
	 *        `null` when no owner type was named and the question was therefore never asked. Being the
	 *        default is not a property of a preset - the same preset is the default one for companies
	 *        and an ordinary one for contacts - so it is answered by the one read that is given an owner
	 *        type ({@see \Bitrix\Crm\V2\Internal\Service\Requisite\PresetResolver::listPresets()}) and is
	 *        `null` everywhere else. A caller that reads `false` where it should read `null` would take
	 *        "not asked" for "not the default".
	 */
	public function __construct(
		public int $id,
		public string $name,
		public bool $active,
		public int $countryId,
		public int $sort,
		public array $fields,
		public ?bool $isDefault = null,
	)
	{
		$declared = [];
		foreach ($fields as $field)
		{
			if ($field->field !== null)
			{
				$declared[$field->field] = true;
			}
		}

		$this->declaredFields = $declared;
	}

	/**
	 * Whether the preset declares $field - the question a write of a requisite asks of every field it
	 * carries, which is why the answer is an index rather than a walk of the composition.
	 *
	 * Only fields the preset **can** decide about are answered for: the ones an administrator adds to a
	 * preset and drops from it. The fields a requisite has as a record - its name, its own preset, its
	 * activity, its sorting - are not part of any composition and are `false` here, so a caller that
	 * checks membership has to ask it of the fields of the jurisdiction alone.
	 */
	public function hasField(string $field): bool
	{
		return isset($this->declaredFields[$field]);
	}

	/**
	 * The same preset with the answer to a question the storage does not keep: is it the default one of
	 * the owner type the caller asked about.
	 *
	 * A copy rather than a change: everything else about a preset comes from one row and stays as it was
	 * read, and the mark is the answer of a read rather than a state the preset can be put into.
	 */
	public function withIsDefault(bool $isDefault): self
	{
		return new self(
			id: $this->id,
			name: $this->name,
			active: $this->active,
			countryId: $this->countryId,
			sort: $this->sort,
			fields: $this->fields,
			isDefault: $isDefault,
		);
	}
}
