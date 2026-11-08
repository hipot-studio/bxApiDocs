<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller\AutoWire;

use Bitrix\Intranet\Internal\Entity\FireWizardConfig;
use Bitrix\Main\Engine\AutoWire\ExactParameter;

trait FireWizardConfigParameterTrait
{
	protected function createFireWizardConfigParameter(): ExactParameter
	{
		return new ExactParameter(
			FireWizardConfig::class,
			'fireWizardConfig',
			function($className, array $options = [], $disableIntegrations = false): FireWizardConfig {
				return new FireWizardConfig(
					filter_var($options['moveWebhooksToSystemUser'] ?? false, FILTER_VALIDATE_BOOLEAN),
					filter_var($disableIntegrations, FILTER_VALIDATE_BOOLEAN),
				);
			},
		);
	}
}
