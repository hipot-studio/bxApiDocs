<?php

namespace Bitrix\Crm;

use Bitrix\Main\Authentication\Context;

/**
 * @internal Carries legacy user-field validation dependencies for a single requisite ORM write.
 */
final class RequisiteLegacyUserFieldValidationContext extends Context
{
	public function __construct(
		private readonly \CUserTypeManager $userFieldManager,
		private readonly ?\CMain $application,
	)
	{
	}

	public function validateUserFields(string $entityId, mixed $primary, array $fields): ?string
	{
		if ($this->userFieldManager->CheckFields($entityId, $primary, $fields, false, false))
		{
			return null;
		}

		$exception = $this->application?->getException();
		if (!$exception)
		{
			return 'Unknown error while checking userfields';
		}

		$message = $exception->getString();
		$this->application->resetException();

		return $message;
	}
}
