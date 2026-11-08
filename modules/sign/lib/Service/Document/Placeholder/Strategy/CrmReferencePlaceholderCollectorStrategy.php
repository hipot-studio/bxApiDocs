<?php

namespace Bitrix\Sign\Service\Document\Placeholder\Strategy;

use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Document\FieldService;
use Bitrix\Sign\Type\BlockCode;

class CrmReferencePlaceholderCollectorStrategy extends AbstractPlaceholderCollectorStrategy
{
	private readonly FieldService $fieldService;

	public function __construct(?FieldService $fieldService = null)
	{
		$this->fieldService = $fieldService ?? Container::instance()->getDocumentFieldService();
	}

	public function create(string $fieldCode, string $fieldType, int $party): string
	{
		return NameHelper::create(
			BlockCode::B2E_MY_REFERENCE,
			$fieldType,
			$party,
			$fieldCode,
		);
	}

	protected function resolveFieldType(string $fieldCode, string $descriptorType): string
	{
		return $this->fieldService->convertCrmFieldType($descriptorType);
	}
}
