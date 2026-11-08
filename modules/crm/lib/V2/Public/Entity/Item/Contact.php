<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Crm\V2\Public\Entity\File;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasCategoriesTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasCompanyBindingsTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasCompanyTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasMultifieldsTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasCategoriesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasCompanyInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasMultifieldsInterface;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Type\Date;

final class Contact extends Item implements HasMultifieldsInterface, HasCompanyInterface, HasCategoriesInterface
{
	public const honorific = 'honorific';
	public const name = 'name';
	public const lastName = 'lastName';
	public const secondName = 'secondName';
	public const post = 'post';
	public const birthdate = 'birthdate';
	public const birthdaySort = 'birthdaySort';
	public const photo = 'photo';
	public const export = 'export';
	public const typeId = 'typeId';
	public const leadId = 'leadId';

	use HasCompanyBindingsTrait;
	use HasCompanyTrait;
	use HasMultifieldsTrait;
	use HasCategoriesTrait;

	private ?string $honorific = null;
	private ?string $name = null;
	private ?string $lastName = null;
	private ?string $secondName = null;
	private ?string $post = null;
	private ?Date $birthdate = null;
	private ?int $birthdaySort = null;
	private ?File $photo = null;
	private ?bool $export = null;
	private ?string $typeId = null;
	private ?int $leadId = null;
	private ?Lead $lead = null;
	/** @var Item[]|null */
	private ?array $companies = null;

	public function getEntityType(): EntityType
	{
		return EntityType::contact();
	}

	public function getLeadId(): ?int
	{
		return $this->leadId;
	}

	public function setLeadId(?int $leadId): static
	{
		$this->leadId = $leadId;
		$this->markChanged(self::leadId);

		return $this;
	}

	public function getLead(): ?Lead
	{
		return $this->lead;
	}

	/** @return Item[]|null */
	public function getCompanies(): ?array
	{
		return $this->companies;
	}

	public function getHonorific(): ?string
	{
		return $this->honorific;
	}

	public function setHonorific(?string $honorific): static
	{
		$this->honorific = $honorific;
		$this->markChanged(self::honorific);

		return $this;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(?string $name): static
	{
		$this->name = $name;
		$this->markChanged(self::name);

		return $this;
	}

	public function getLastName(): ?string
	{
		return $this->lastName;
	}

	public function setLastName(?string $lastName): static
	{
		$this->lastName = $lastName;
		$this->markChanged(self::lastName);

		return $this;
	}

	public function getSecondName(): ?string
	{
		return $this->secondName;
	}

	public function setSecondName(?string $secondName): static
	{
		$this->secondName = $secondName;
		$this->markChanged(self::secondName);

		return $this;
	}

	public function getPost(): ?string
	{
		return $this->post;
	}

	public function setPost(?string $post): static
	{
		$this->post = $post;
		$this->markChanged(self::post);

		return $this;
	}

	public function getBirthdate(): ?Date
	{
		return $this->birthdate;
	}

	public function setBirthdate(?Date $birthdate): static
	{
		$this->birthdate = $birthdate;
		$this->markChanged(self::birthdate);

		return $this;
	}

	public function getBirthdaySort(): ?int
	{
		return $this->birthdaySort;
	}

	public function getPhoto(): ?File
	{
		return $this->photo;
	}

	public function setPhoto(?File $photo): static
	{
		$this->photo = $photo;
		$this->markChanged(self::photo);

		return $this;
	}

	public function getExport(): ?bool
	{
		return $this->export;
	}

	public function setExport(?bool $export): static
	{
		$this->export = $export;
		$this->markChanged(self::export);

		return $this;
	}

	public function getTypeId(): ?string
	{
		return $this->typeId;
	}

	public function setTypeId(?string $typeId): static
	{
		$this->typeId = $typeId;
		$this->markChanged(self::typeId);

		return $this;
	}

	public function getFullName(): string
	{
		return trim(implode(' ', array_filter([
			$this->lastName,
			$this->name,
			$this->secondName,
		])));
	}

	protected function buildCaption(): string
	{
		$fullName = $this->getFullName();

		return $fullName !== '' ? $fullName : ($this->getTitle() ?? '');
	}

	public function internalSet(string $fieldName, mixed $value): static
	{
		if ($this->internalSetCompanyBindingField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetCompanyField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetMultifieldField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetCategoryField($fieldName, $value))
		{
			return $this;
		}

		match ($fieldName)
		{
			'companies' => $this->companies = $value,
			self::honorific => $this->honorific = $value,
			self::name => $this->name = $value,
			self::lastName => $this->lastName = $value,
			self::secondName => $this->secondName = $value,
			self::post => $this->post = $value,
			self::birthdate => $this->birthdate = $value,
			self::birthdaySort => $this->birthdaySort = $value,
			self::photo => $this->photo = $value,
			self::export => $this->export = $value,
			self::typeId => $this->typeId = $value,
			self::leadId => $this->leadId = $value,
			'lead' => $this->lead = $value,
			default => parent::internalSet($fieldName, $value),
		};

		return $this;
	}
}
