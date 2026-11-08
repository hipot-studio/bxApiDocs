<?php

declare(strict_types=1);

namespace Bitrix\CrmMobile\Controller;

use Bitrix\CrmMobile\Filter\CrmListContext;
use Bitrix\CrmMobile\Filter\FieldDescriptorNormalizer;
use Bitrix\Main\Error;
use Bitrix\Mobile\Filter\FilterActionsTrait;
use Bitrix\Mobile\Filter\ListContext;

/**
 * Unified filter of the CRM lists. The transport comes from the core trait; a CRM list is named by
 * its entity type and funnel - the pair the rest of the mobile CRM already speaks.
 */
class Filter extends Base
{
	use FilterActionsTrait;

	public function configureActions(): array
	{
		return $this->configureFilterActions();
	}

	protected function createListContext(array $context): ?ListContext
	{
		$entityTypeName = (string)($context['entityTypeName'] ?? '');
		$categoryId = (int)($context['categoryId'] ?? 0);

		$list = CrmListContext::make($entityTypeName, $categoryId);
		if ($list === null)
		{
			$this->addError(new Error('Unsupported entity type.', 'UNSUPPORTED_ENTITY_TYPE'));
		}

		return $list;
	}

	protected function getNormalizer(): FieldDescriptorNormalizer
	{
		return new FieldDescriptorNormalizer();
	}
}
