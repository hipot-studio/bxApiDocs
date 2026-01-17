<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity;

use Bitrix\Bizproc\Public\Entity\Document\DocumentComplexType;
use Bitrix\Bizproc\Public\Entity\Trigger\Section;

final class Configurator implements \Bitrix\Main\Type\Contract\Arrayable
{
	public const SECTION_ARRAY_KEY = 'section';
	public const DOCUMENT_TYPE_ARRAY_KEY = 'documentComplexType';
	public const ACTIVITY_TYPE_KEY = 'activityType';
	public const PROPERTIES_MAP_KEY = 'propertiesMap';

	protected array $propertiesMap = [];

	public function __construct(
		protected string $activityType = '',
		protected ?DocumentComplexType $documentComplexType = null,
		protected ?Section $section = null,
	)
	{}

	public function setPropertiesMap(array $map): Configurator
	{
		$this->propertiesMap = $map;

		return $this;
	}

	/**
	 * @param string $activityType
	 *
	 * @return Configurator
	 */
	public function setActivityType(string $activityType): Configurator
	{
		$this->activityType = $activityType;

		return $this;
	}

	public function getPropertiesMap(): array
	{
		return $this->propertiesMap;
	}

	/**
	 * @return DocumentComplexType|null
	 */
	public function getDocumentComplexType(): ?DocumentComplexType
	{
		return $this->documentComplexType;
	}

	/**
	 * @param DocumentComplexType|null $documentComplexType
	 *
	 * @return Configurator
	 */
	public function setDocumentComplexType(?DocumentComplexType $documentComplexType): Configurator
	{
		$this->documentComplexType = $documentComplexType;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getActivityType(): string
	{
		return $this->activityType;
	}

	public function getFirstPropertyByType(string $type): ?array
	{
		foreach ($this->propertiesMap as $propertyName => $property)
		{
			if (($property['Type'] ?? '') === $type)
			{
				return $property;
			}
		}

		return null;
	}

	public function getSection(): ?Section
	{
		return $this->section;
	}

	public function setSection(?Section $section): Configurator
	{
		$this->section = $section;

		return $this;
	}

	public function toArray(): array
	{
		return [
			self::DOCUMENT_TYPE_ARRAY_KEY => $this->documentComplexType?->toArray(),
			self::SECTION_ARRAY_KEY => $this->section?->toArray(),
			self::ACTIVITY_TYPE_KEY => $this->activityType,
			self::PROPERTIES_MAP_KEY => $this->propertiesMap,
		];
	}
}
