<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\LegalRegistry;

use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FeatureGate;
use Bitrix\Crm\V2\Internal\Service\LegalRegistry\UsageService;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Main\Command\AbstractCommand;

final class UsageCommand extends AbstractCommand
{
	public function __construct(
		private readonly int $userId,
		private readonly string $operation,
		private readonly int $formId,
		private readonly ?int $documentId,
		private readonly ?int $versionId,
		private readonly bool $checked,
		private readonly bool $required,
		private readonly int $expectedSourceRevision,
	) {}

	protected function execute(): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		return UsageService::getInstance()->execute($this->userId, $this->operation, $this->formId,
			$this->documentId, $this->versionId, $this->checked, $this->required, $this->expectedSourceRevision);
	}
}
