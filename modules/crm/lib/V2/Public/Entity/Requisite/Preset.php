<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Requisite;

use Bitrix\Main\ArgumentTypeException;

/**
 * A preset of a requisite - the choice of a jurisdiction, made once and answered for by every requisite
 * that names it.
 *
 * The storage keeps a cell for every legal detail the product knows, and a requisite fills only the few
 * its preset shows ({@see Requisite}). So a preset is what decides which fields a requisite means
 * anything by, what the interface calls them and in what order it shows them - and the composition
 * below is that decision, as the portal configured it.
 *
 * Read-only: managing the presets of a portal is not part of this group, and a preset that comes from
 * it is what the portal has rather than something to change. What a client may do with one is name it
 * when it creates or changes a requisite.
 */
final readonly class Preset
{
	/** @var PresetField[] */
	private array $fields;

	/**
	 * The composition comes as an array rather than as a variadic tail, and that is the shape of a
	 * constructor that can still be added to: a parameter cannot be declared after a variadic one, and
	 * one declared before it moves the positions every caller already passes. A preset has a property
	 * known to be coming - the country it stands for, which is what decides the composition of the bank
	 * details of a requisite - so the tail is left open for it. The price is that the type of the entries
	 * is checked below instead of by the engine.
	 *
	 * @param bool|null $isDefault whether the preset is the default one **of a type of owner**, and
	 *        `null` when no owner type was named and the question was therefore never asked. Being the
	 *        default is not a property of a preset - the same preset is the default one for companies
	 *        and an ordinary one for contacts - so it is answered by a read that is given an owner type
	 *        and is `null` in every other. A consumer that reads `false` where it should read `null`
	 *        takes "not asked" for "not the default". The mark is worked out on a read and is kept
	 *        nowhere, so there is no column of it to look for.
	 * @param int $sort the order in which the interface shows the presets of the portal.
	 * @param PresetField[] $fields the composition, in the order the interface shows it.
	 * @throws ArgumentTypeException the composition carries something that is not a {@see PresetField}.
	 */
	public function __construct(
		private int $id,
		private string $name,
		private ?bool $isDefault,
		private int $sort,
		array $fields = [],
	)
	{
		foreach ($fields as $field)
		{
			if (!$field instanceof PresetField)
			{
				throw new ArgumentTypeException('fields', PresetField::class . '[]');
			}
		}

		$this->fields = array_values($fields);
	}

	public function getId(): int
	{
		return $this->id;
	}

	/** The name of the preset as the portal gave it, not a name of the product. */
	public function getName(): string
	{
		return $this->name;
	}

	public function getIsDefault(): ?bool
	{
		return $this->isDefault;
	}

	public function getSort(): int
	{
		return $this->sort;
	}

	/** @return PresetField[] */
	public function getFields(): array
	{
		return $this->fields;
	}
}
