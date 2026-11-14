<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\LegalRegistry;

use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FeatureGate;
use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FormSubmissionService;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Main\Command\AbstractCommand;

final class FormSubmissionCommand extends AbstractCommand
{
	/** The request hash is computed by the server transport, never taken from a client hash field. */
	public function __construct(
		private readonly int $formId,
		private readonly string $presentationToken,
		private readonly string $attemptKey,
		private readonly array $answers,
		private readonly string $normalizedRequestHash,
	) {}

	/** Checks an existing signed freeze before the transport loads or mutates the current form. */
	public function replay(): ?RegistryResult
	{
		return (new FormSubmissionService())->replay(
			$this->formId, $this->presentationToken, $this->attemptKey, $this->answers, $this->normalizedRequestHash,
		);
	}

	protected function execute(): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess()) { return $availability; }

		return (new FormSubmissionService())->prepare(
			$this->formId, $this->presentationToken, $this->attemptKey, $this->answers, $this->normalizedRequestHash,
		);
	}
}
