<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\LegalRegistry;

use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FeatureGate;
use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FormPresentationService;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;

final class PresentationProvider
{
	/** Issues a fresh display after rechecking the live public form security context. */
	public function issueForPublicForm(int $formId, array $verifiedFormContext): RegistryResult
	{
		$availability = FeatureGate::requireEnabled();
		if (!$availability->isSuccess()) { return $availability; }

		return (new FormPresentationService())->issueForPublicForm($formId, $verifiedFormContext);
	}
}
