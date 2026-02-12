<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\Events\OnGetDocumentType;

use Bitrix\Bizproc\Public\Event\Document\OnGetDocumentTypeEvent\DocumentTypeFilter;

class CrmDocumentTypeFilter extends DocumentTypeFilter
{
	protected array $parameters = [];

	public function __construct()
	{
		$this->parameters = [
			'onlyDynamic' => false,
			'onlyBasic' => false,
			'onlyEntities' => null,
		];
	}

	public function loadFromArray(array $parameters): void
	{
		$this->parameters = [
			'onlyDynamic' => isset($parameters['onlyDynamic']) && (bool)$parameters['onlyDynamic'],
			'onlyBasic' => isset($parameters['onlyBasic']) && (bool)$parameters['onlyBasic'],
			'onlyEntities' =>
				isset($parameters['onlyEntities']) && is_array($parameters['onlyEntities'])
					? $parameters['onlyEntities']
					: null
			,
		];
	}

	public function isOnlyDynamic(): bool
	{
		return (bool)($this->parameters['onlyDynamic'] ?? false);
	}

	public function isOnlyBasic(): bool
	{
		return (bool)($this->parameters['onlyBasic'] ?? false);
	}

	public function isOnlyCertainEntities(): bool
	{
		return $this->parameters['onlyEntities'] !== null;
	}

	public function getCertainEntities(): array
	{
		return is_array($this->parameters['onlyEntities']) ? $this->parameters['onlyEntities'] : [];
	}
}
