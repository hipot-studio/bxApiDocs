<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Document;

class InspectorService
{
	public function __construct()
	{}

	public function getComplexType(array $complexId): ?array
	{
		try
		{
			$documentType = \CBPRuntime::getRuntime()->getDocumentService()->getDocumentType($complexId);

			return is_array($documentType) ? $documentType : null;
		}
		catch (\Exception $e)
		{
			return null;
		}
	}

	public function getValidComplexType(array $complexId, ?array $complexType = null): ?array
	{
		if ($complexType && \CBPHelper::isEqualDocumentEntity($complexType, $complexId))
		{
			return $complexType;
		}

		return $this->getComplexType($complexId);
	}
}
