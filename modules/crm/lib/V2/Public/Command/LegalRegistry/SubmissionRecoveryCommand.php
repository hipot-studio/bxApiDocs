<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\LegalRegistry;

use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FormSubmissionRecovery;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Main\Command\AbstractCommand;

final class SubmissionRecoveryCommand extends AbstractCommand
{
	public function __construct(private readonly int $limit = 100, private readonly int $cursor = 0) {}

	protected function execute(): RegistryResult
	{
		return (new FormSubmissionRecovery())->recover($this->limit, $this->cursor);
	}
}
