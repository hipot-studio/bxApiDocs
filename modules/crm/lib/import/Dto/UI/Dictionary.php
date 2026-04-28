<?php

namespace Bitrix\Crm\Import\Dto\UI;

use Bitrix\Crm\Import\Dto\UI\SettingsControl\SelectOption;
use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class Dictionary implements JsonSerializable, Arrayable
{
	/** @var SelectOption[] */
	private array $encodings = [];

	/** @var SelectOption[] */
	private array $nameFormats = [];

	/** @var SelectOption[] */
	private array $delimiters = [];

	/** @var SelectOption[] */
	private array $requisitePresets = [];

	/** @var SelectOption[] */
	private array $contactTypes = [];

	/** @var SelectOption[] */
	private array $sources = [];

	/** @var SelectOption[] */
	private array $duplicateControlBehaviors = [];

	/** @var SelectOption[] */
	private array $duplicateControlTargets = [];

	private bool $isDuplicateControlPermitted = false;

	public function getEncodings(): array
	{
		return $this->encodings;
	}

	/**
	 * @param SelectOption[] $encodings
	 * @return $this
	 */
	public function setEncodings(array $encodings): Dictionary
	{
		$this->encodings = $encodings;

		return $this;
	}

	public function getNameFormats(): array
	{
		return $this->nameFormats;
	}

	/**
	 * @param SelectOption[] $nameFormats
	 * @return $this
	 */
	public function setNameFormats(array $nameFormats): Dictionary
	{
		$this->nameFormats = $nameFormats;

		return $this;
	}

	public function getDelimiters(): array
	{
		return $this->delimiters;
	}

	/**
	 * @param SelectOption[] $delimiters
	 * @return $this
	 */
	public function setDelimiters(array $delimiters): Dictionary
	{
		$this->delimiters = $delimiters;

		return $this;
	}

	public function getRequisitePresets(): array
	{
		return $this->requisitePresets;
	}

	/**
	 * @param SelectOption[] $requisitePresets
	 * @return $this
	 */
	public function setRequisitePresets(array $requisitePresets): Dictionary
	{
		$this->requisitePresets = $requisitePresets;

		return $this;
	}

	public function getContactTypes(): array
	{
		return $this->contactTypes;
	}

	/**
	 * @param SelectOption[] $contactTypes
	 * @return $this
	 */
	public function setContactTypes(array $contactTypes): Dictionary
	{
		$this->contactTypes = $contactTypes;

		return $this;
	}

	public function getSources(): array
	{
		return $this->sources;
	}

	/**
	 * @param SelectOption[] $sources
	 * @return $this
	 */
	public function setSources(array $sources): Dictionary
	{
		$this->sources = $sources;

		return $this;
	}

	public function getDuplicateControlBehaviors(): array
	{
		return $this->duplicateControlBehaviors;
	}

	/**
	 * @param SelectOption[] $duplicateControlBehaviors
	 * @return $this
	 */
	public function setDuplicateControlBehaviors(array $duplicateControlBehaviors): Dictionary
	{
		$this->duplicateControlBehaviors = $duplicateControlBehaviors;

		return $this;
	}

	public function getDuplicateControlTargets(): array
	{
		return $this->duplicateControlTargets;
	}

	/**
	 * @param SelectOption[] $duplicateControlTargets
	 * @return $this
	 */
	public function setDuplicateControlTargets(array $duplicateControlTargets): Dictionary
	{
		$this->duplicateControlTargets = $duplicateControlTargets;

		return $this;
	}

	public function isDuplicateControlPermitted(): bool
	{
		return $this->isDuplicateControlPermitted;
	}

	public function setDuplicateControlPermitted(bool $isDuplicateControlPermitted): Dictionary
	{
		$this->isDuplicateControlPermitted = $isDuplicateControlPermitted;

		return $this;
	}

	public function toArray(): array
	{
		$toArray = static fn (array $selectOptions) => array_map(static fn (SelectOption $selectOption) => $selectOption->toArray(), $selectOptions);

		return [
			'encodings' => $toArray($this->encodings),
			'nameFormats' => $toArray($this->nameFormats),
			'delimiters' => $toArray($this->delimiters),
			'requisitePresets' => $toArray($this->requisitePresets),
			'contactTypes' => $toArray($this->contactTypes),
			'sources' => $toArray($this->sources),
			'duplicateControlBehaviors' => $toArray($this->duplicateControlBehaviors),
			'duplicateControlTargets' => $toArray($this->duplicateControlTargets),
			'isDuplicateControlPermitted' => $this->isDuplicateControlPermitted,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
