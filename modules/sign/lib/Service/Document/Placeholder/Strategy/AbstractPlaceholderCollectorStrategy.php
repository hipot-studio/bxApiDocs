<?php

namespace Bitrix\Sign\Service\Document\Placeholder\Strategy;

use Bitrix\Sign\Item\Document\Placeholder\PlaceholderCollection;
use Bitrix\Sign\Contract\Document;
use Bitrix\Sign\Item;
use Bitrix\Sign\Service\Container;
use Psr\Log\LoggerInterface;

abstract class AbstractPlaceholderCollectorStrategy implements Document\PlaceholderCollectorInterface
{
	private ?LoggerInterface $logger = null;

	public function createFromFields(array $fields, int $party): PlaceholderCollection
	{
		$placeholderCollection = new PlaceholderCollection();
		foreach ($fields as $field)
		{
			if (!isset($field['name'], $field['type'], $field['caption']))
			{
				continue;
			}

			$fieldCode = (string)$field['name'];
			$fieldType = $this->resolveFieldType($fieldCode, (string)$field['type']);

			$placeholderCollection->add(
				new Item\Document\Placeholder\Placeholder(
					$field['caption'],
					$this->create($fieldCode, $fieldType, $party),
					$this->resolveContractType($fieldCode, $fieldType),
				),
			);
		}

		return $placeholderCollection;
	}

	/**
	 * Builds the placeholder code from the already resolved field type.
	 */
	abstract public function create(string $fieldCode, string $fieldType, int $party): string;

	/**
	 * Semantic type of the field. Every source names its own types (user field types, CRM web form
	 * types, an enumerator of the external HR system), so the raw descriptor is only an input here.
	 */
	abstract protected function resolveFieldType(string $fieldCode, string $descriptorType): string;

	private function resolveContractType(string $fieldName, string $fieldType): ?string
	{
		if (Item\Document\Placeholder\Placeholder::isSupportedType($fieldType))
		{
			return $fieldType;
		}

		$this->getLogger()->debug(
			'Placeholder field type is out of the fields list contract',
			[
				'fieldName' => $fieldName,
				'fieldType' => $fieldType,
			],
		);

		return null;
	}

	private function getLogger(): LoggerInterface
	{
		return $this->logger ??= Container::instance()->getLogger('Service');
	}
}
