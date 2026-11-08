<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\DetailPageLayout;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\DetailPageLayout\AudienceItemDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\DetailPageLayout\ConfigurationElementDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\DetailPageLayout\ConfigurationSectionDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\DetailPageLayout\LayoutDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\DetailPageLayout\LayoutFieldsDto;
use Bitrix\Crm\V2\Public\Entity\Layout\AccessCode;
use Bitrix\Crm\V2\Public\Entity\Layout\Layout;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutAudience;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutConfiguration;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutName;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Structure\Structure;

final class LayoutDtoMapper
{
	public function getDtoByLayout(Layout $layout): LayoutDto
	{
		$dto = LayoutDto::create();
		Structure::addDto($dto);
		$dto->id = $layout->getId()->getValue();
		$dto->configuration = new DtoCollection(ConfigurationSectionDto::class);
		foreach ($layout->getConfiguration()->getSections() as $section)
		{
			$sectionDto = ConfigurationSectionDto::create();
			$sectionDto->name = $section['name'];
			$sectionDto->title = $section['title'];
			$sectionDto->elements = new DtoCollection(ConfigurationElementDto::class);
			foreach ($section['elements'] as $element)
			{
				$elementDto = ConfigurationElementDto::create();
				$elementDto->name = $element['name'];
				if (array_key_exists('optionFlags', $element))
				{
					$elementDto->optionFlags = $element['optionFlags'];
				}
				if (array_key_exists('options', $element))
				{
					$elementDto->options = (object)$element['options'];
				}
				$sectionDto->elements->add($elementDto);
			}
			$dto->configuration->add($sectionDto);
		}

		if ($layout->getId()->isCustom())
		{
			$name = $layout->getName();
			$audience = $layout->getAudience();
			if ($name === null || $audience === null)
			{
				throw new \LogicException('A custom layout must have a name and an audience.');
			}

			$dto->name = $name->getValue();
			$dto->audience = new DtoCollection(AudienceItemDto::class);
			foreach ($audience->getAccessCodes() as $accessCode)
			{
				$audienceDto = AudienceItemDto::create();
				$audienceDto->type = $accessCode->getType();
				$audienceDto->id = $accessCode->getId();
				$dto->audience->add($audienceDto);
			}
		}

		return $dto;
	}

	/**
	 * @param list<Layout> $layouts
	 */
	public function getDtoCollectionByLayouts(array $layouts): DtoCollection
	{
		$collection = new DtoCollection(LayoutDto::class);
		foreach ($layouts as $layout)
		{
			$collection->add($this->getDtoByLayout($layout));
		}

		return $collection;
	}

	public function getFieldsByDto(LayoutFieldsDto $dto): Result
	{
		$fields = [];
		if (isset($dto->configuration))
		{
			$sections = $dto->configuration->toArray();
			foreach ($sections as &$section)
			{
				foreach ($section['elements'] as &$element)
				{
					if (array_key_exists('options', $element))
					{
						$element['options'] = (array)$element['options'];
					}
				}
				unset($element);
			}
			unset($section);

			$result = LayoutConfiguration::create($sections);
			if (!$result->isSuccess())
			{
				return $result;
			}
			$fields['configuration'] = $result->getData()['configuration'];
		}
		if (isset($dto->name))
		{
			$result = LayoutName::create($dto->name);
			if (!$result->isSuccess())
			{
				return $result;
			}
			$fields['name'] = $result->getData()['name'];
		}
		if (isset($dto->audience))
		{
			$accessCodes = [];
			foreach ($dto->audience as $audienceDto)
			{
				$result = AccessCode::create($audienceDto->type, $audienceDto->id);
				if (!$result->isSuccess())
				{
					return $result;
				}
				$accessCodes[] = $result->getData()['accessCode'];
			}

			$result = LayoutAudience::create($accessCodes);
			if (!$result->isSuccess())
			{
				return $result;
			}
			$fields['audience'] = $result->getData()['audience'];
		}
		return (new Result())->setData($fields);
	}
}
