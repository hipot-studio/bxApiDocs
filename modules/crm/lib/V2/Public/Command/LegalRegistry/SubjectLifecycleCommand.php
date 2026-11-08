<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\LegalRegistry;

use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FeatureGate;
use Bitrix\Crm\V2\Internal\Service\LegalRegistry\SubjectLifecycleService;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubjectTransition;
use Bitrix\Main\Command\AbstractCommand;

final class SubjectLifecycleCommand extends AbstractCommand
{
	public function __construct(public readonly int $userId, public readonly SubjectTransition $transition)
	{
	}

	protected function execute(): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		return (new SubjectLifecycleService())->transition($this->userId, $this->transition);
	}
}
