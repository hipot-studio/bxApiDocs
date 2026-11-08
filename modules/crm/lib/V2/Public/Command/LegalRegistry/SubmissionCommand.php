<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\LegalRegistry;

use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FeatureGate;
use Bitrix\Crm\V2\Internal\Service\LegalRegistry\SubmissionService;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubmissionManifest;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubmissionResolution;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\TrustedSubmissionContext;
use Bitrix\Main\Command\AbstractCommand;

final class SubmissionCommand extends AbstractCommand
{
	public function __construct(
		private readonly TrustedSubmissionContext $context,
		private readonly string $operation,
		private readonly SubmissionManifest $manifest,
		private readonly ?SubmissionResolution $resolution = null,
	) {}

	protected function execute(): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		return SubmissionService::getInstance()->execute($this->context, $this->operation,
			$this->manifest, $this->resolution);
	}
}
