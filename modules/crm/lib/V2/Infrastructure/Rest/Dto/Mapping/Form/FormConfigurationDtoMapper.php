<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Configuration;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Configuration\FormConfiguration;
use Bitrix\Rest\V3\Dto\DtoCollection;

final class FormConfigurationDtoMapper
{
	public function getDto(FormConfiguration $configuration): Configuration\ConfigurationDto
	{
		$dto = new Configuration\ConfigurationDto();
		$dto->entitySchemes = $this->getEntitySchemeCollection($configuration->entitySchemes);
		$dto->duplicateModes = $this->getNamedCodeCollection($configuration->duplicateModes);
		$dto->lead = $this->getLeadDto($configuration->lead);
		$dto->dealCategories = $this->getNamedIdCollection($configuration->dealCategories);
		$dto->smartProcesses = $this->getSmartProcessCollection($configuration->smartProcesses);
		$dto->languages = $this->getNamedCodeCollection($configuration->languages);
		$dto->currencies = $this->getCurrencyCollection($configuration->currencies);
		$dto->fieldTypes = $this->getFieldTypeCollection($configuration->fieldTypes);
		$dto->paySystems = $this->getNamedIdCollection($configuration->paySystems);
		$dto->permissions = $this->getPermissionsDto($configuration->permissions);
		$dto->limits = $this->getLimitsDto($configuration->limits);
		$dto->product = $this->getProductDto($configuration->product);
		$dto->region = $configuration->region;

		return $dto;
	}

	private function getEntitySchemeCollection(array $items): DtoCollection
	{
		$collection = new DtoCollection(Configuration\EntitySchemeDto::class);
		foreach ($items as $item)
		{
			$dto = new Configuration\EntitySchemeDto();
			$dto->id = $item['id'];
			$dto->name = $item['name'];
			$dto->description = $item['description'];
			$dto->entityNames = $item['entityNames'];
			$dto->mainEntityName = $item['mainEntityName'];
			$dto->hasInvoice = $item['hasInvoice'];
			$dto->dynamic = $item['dynamic'];

			$collection->add($dto);
		}

		return $collection;
	}

	private function getNamedCodeCollection(array $items): DtoCollection
	{
		$collection = new DtoCollection(Configuration\NamedCodeDto::class);
		foreach ($items as $item)
		{
			$dto = new Configuration\NamedCodeDto();
			$dto->id = $item['id'];
			$dto->name = $item['name'];

			$collection->add($dto);
		}

		return $collection;
	}

	private function getNamedIdCollection(array $items): DtoCollection
	{
		$collection = new DtoCollection(Configuration\NamedIdDto::class);
		foreach ($items as $item)
		{
			$dto = new Configuration\NamedIdDto();
			$dto->id = $item['id'];
			$dto->name = $item['name'];

			$collection->add($dto);
		}

		return $collection;
	}

	private function getSmartProcessCollection(array $items): DtoCollection
	{
		$collection = new DtoCollection(Configuration\SmartProcessDto::class);
		foreach ($items as $item)
		{
			$dto = new Configuration\SmartProcessDto();
			$dto->entityTypeId = $item['entityTypeId'];
			$dto->entityName = $item['entityName'];
			$dto->name = $item['name'];
			$dto->categories = $this->getNamedIdCollection($item['categories']);

			$collection->add($dto);
		}

		return $collection;
	}

	private function getCurrencyCollection(array $items): DtoCollection
	{
		$collection = new DtoCollection(Configuration\CurrencyDto::class);
		foreach ($items as $item)
		{
			$dto = new Configuration\CurrencyDto();
			$dto->id = $item['code'];
			$dto->fullName = $item['name'];
			$dto->formatString = $item['format'];

			$collection->add($dto);
		}

		return $collection;
	}

	private function getFieldTypeCollection(array $items): DtoCollection
	{
		$collection = new DtoCollection(Configuration\FieldTypeDto::class);
		foreach ($items as $item)
		{
			$dto = new Configuration\FieldTypeDto();
			$dto->id = $item['id'];
			$dto->name = $item['name'];
			$dto->parameters = $item['parameters'];

			$collection->add($dto);
		}

		return $collection;
	}

	private function getLeadDto(array $lead): Configuration\LeadDto
	{
		$dto = new Configuration\LeadDto();
		$dto->enabled = $lead['enabled'];

		return $dto;
	}

	private function getPermissionsDto(array $permissions): Configuration\PermissionsDto
	{
		$dto = new Configuration\PermissionsDto();
		$dto->canRead = $permissions['canRead'];
		$dto->canEdit = $permissions['canEdit'];

		return $dto;
	}

	private function getLimitsDto(array $limits): Configuration\LimitsDto
	{
		$dto = new Configuration\LimitsDto();
		$dto->activeForms = null;
		if ($limits['activeForms'] !== null)
		{
			$dto->activeForms = new Configuration\ActiveFormLimitDto();
			$dto->activeForms->current = $limits['activeForms']['current'];
			$dto->activeForms->maximum = $limits['activeForms']['maximum'];
		}

		return $dto;
	}

	private function getProductDto(array $product): Configuration\ProductDto
	{
		$dto = new Configuration\ProductDto();
		$dto->isCloud = $product['isCloud'];
		$dto->isRegionRussian = $product['isRegionRussian'];

		return $dto;
	}
}
