<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\LegalRegistry;

use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FeatureGate;
use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FormBindingsService;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\FormBindings;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Main\Command\AbstractCommand;

final class FormBindingsCommand extends AbstractCommand
{
	public function __construct(
		private readonly int $userId,
		private readonly int $formId,
		private readonly FormBindings $bindings,
	) {}

	protected function execute(): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		return FormBindingsService::getInstance()->save($this->userId, $this->formId, $this->bindings);
	}
}
