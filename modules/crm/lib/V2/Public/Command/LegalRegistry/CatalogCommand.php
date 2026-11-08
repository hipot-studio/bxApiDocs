<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\LegalRegistry;

use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FeatureGate;
use Bitrix\Crm\V2\Internal\Service\LegalRegistry\CatalogService;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogVersion;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Main\Command\AbstractCommand;

final class CatalogCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly string $operation,
		public readonly ?int $documentId = null,
		public readonly ?CatalogVersion $version = null,
		public readonly ?string $name = null,
		public readonly ?int $expectedRevision = null,
	)
	{
	}

	protected function execute(): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		return CatalogService::getInstance()->execute($this->userId, $this->operation, $this->documentId,
			$this->version, $this->name, $this->expectedRevision);
	}
}
