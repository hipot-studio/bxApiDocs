<?php

namespace Bitrix\Sign\Service\Document\Placeholder\Strategy;

use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\HumanResources\HcmLinkFieldService;
use Bitrix\Sign\Type\BlockCode;

class HcmLinkPlaceholderCollectorStrategy extends AbstractPlaceholderCollectorStrategy
{
	private readonly HcmLinkFieldService $hcmLinkFieldService;

	public function __construct(
		?HcmLinkFieldService $hcmLinkFieldService = null,
	)
	{
		$this->hcmLinkFieldService = $hcmLinkFieldService ?? Container::instance()->getHcmLinkFieldService();
	}

	public function create(string $fieldCode, string $fieldType, int $party): string
	{
		return NameHelper::create(
			BlockCode::B2E_HCMLINK_REFERENCE,
			$fieldType,
			$party,
			$fieldCode,
		);
	}

	/**
	 * The descriptor of a field of the external HR system carries an integer enumerator of that
	 * system; the same value is encoded in the field name, and the service maps it to a field type.
	 */
	protected function resolveFieldType(string $fieldCode, string $descriptorType): string
	{
		return $this->hcmLinkFieldService->getFieldTypeByName($fieldCode);
	}
}
