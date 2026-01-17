<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

class DocumentDescription implements Arrayable
{
	public function __construct(
		public readonly string $module,
		public readonly string $entityType,
		public readonly string $documentType,
	) {}

	public function toArray(): array
	{
		return [
			'module' => $this->module,
			'entityType' => $this->entityType,
			'documentType' => $this->documentType,
		];
	}

	/**
	 * @return list<string> ['module', 'entityType', 'documentType']
	 */
	public function toBizprocComplexType(): array
	{
		return [$this->module, $this->entityType, $this->documentType];
	}
}