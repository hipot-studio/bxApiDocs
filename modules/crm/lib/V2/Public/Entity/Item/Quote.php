<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasCompanyInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasContactBindingsInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasBeginCloseDatesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasProductsInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasStagesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasCompanyTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasContactBindingsTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasMyCompanyTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasProductsTrait;
use Bitrix\Crm\V2\Public\Entity\Item\Trait\HasStagesTrait;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Type\Date;

final class Quote extends Item implements
	HasProductsInterface,
	HasContactBindingsInterface,
	HasCompanyInterface,
	HasStagesInterface,
	HasBeginCloseDatesInterface
{
	public const content = 'content';
	public const terms = 'terms';
	public const quoteNumber = 'quoteNumber';
	public const dealId = 'dealId';
	public const deal = 'deal';
	public const leadId = 'leadId';
	public const actualDate = 'actualDate';
	public const personTypeId = 'personTypeId';
	public const locationId = 'locationId';

	use HasStagesTrait;
	use HasProductsTrait;
	use HasContactBindingsTrait;
	use HasCompanyTrait;
	use HasMyCompanyTrait;

	private ?string $content = null;
	private ?string $terms = null;
	private ?string $quoteNumber = null;
	private ?int $dealId = null;
	private ?int $leadId = null;
	private ?Deal $deal = null;
	private ?Lead $lead = null;
	private ?Date $actualDate = null;
	private ?int $personTypeId = null;
	private ?int $locationId = null;

	public function getEntityType(): EntityType
	{
		return EntityType::quote();
	}

	public function getContent(): ?string
	{
		return $this->content;
	}

	public function setContent(?string $content): static
	{
		$this->content = $content;
		$this->markChanged(self::content);

		return $this;
	}

	public function getTerms(): ?string
	{
		return $this->terms;
	}

	public function setTerms(?string $terms): static
	{
		$this->terms = $terms;
		$this->markChanged(self::terms);

		return $this;
	}

	public function getQuoteNumber(): ?string
	{
		return $this->quoteNumber;
	}

	public function setQuoteNumber(?string $quoteNumber): static
	{
		$this->quoteNumber = $quoteNumber;
		$this->markChanged(self::quoteNumber);

		return $this;
	}

	public function getDealId(): ?int
	{
		return $this->dealId;
	}

	public function setDealId(?int $dealId): static
	{
		$this->dealId = $dealId;
		$this->markChanged(self::dealId);

		return $this;
	}

	public function getDeal(): ?Deal
	{
		return $this->deal;
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

	public function getActualDate(): ?Date
	{
		return $this->actualDate;
	}

	public function setActualDate(?Date $actualDate): static
	{
		$this->actualDate = $actualDate;
		$this->markChanged(self::actualDate);

		return $this;
	}

	public function getPersonTypeId(): ?int
	{
		return $this->personTypeId;
	}

	public function setPersonTypeId(?int $personTypeId): static
	{
		$this->personTypeId = $personTypeId;
		$this->markChanged(self::personTypeId);

		return $this;
	}

	public function getLocationId(): ?int
	{
		return $this->locationId;
	}

	public function setLocationId(?int $locationId): static
	{
		$this->locationId = $locationId;
		$this->markChanged(self::locationId);

		return $this;
	}

	public function internalSet(string $fieldName, mixed $value): static
	{
		if ($this->internalSetStageField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetProductField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetContactBindingField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetCompanyField($fieldName, $value))
		{
			return $this;
		}
		if ($this->internalSetMyCompanyField($fieldName, $value))
		{
			return $this;
		}
		if ($fieldName === self::locationId)
		{
			$this->locationId = $value;

			return $this;
		}

		match ($fieldName)
		{
			self::content => $this->content = $value,
			self::terms => $this->terms = $value,
			self::quoteNumber => $this->quoteNumber = $value,
			self::dealId => $this->dealId = $value,
			self::deal => $this->deal = $value,
			self::leadId => $this->leadId = $value,
			'lead' => $this->lead = $value,
			self::actualDate => $this->actualDate = $value,
			self::personTypeId => $this->personTypeId = $value,
			default => parent::internalSet($fieldName, $value),
		};

		return $this;
	}
}
