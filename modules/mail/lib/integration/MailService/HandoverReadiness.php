<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationOperationSchema;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationService;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Whether the portal accepts a hand-over kit at all - the explicit readiness contract of
 * the receiving side, asked before mailservice orders the credentials of a new mailbox
 * from the external host. The question is about the portal, so it names no mailbox.
 *
 * The answer mirrors the one mailservice reads out of the module by reflection over its
 * internal classes (ReceivingSideOnThePortal, on their side): the emergency brake, the
 * feature switch and the schema of the operations table. That parity is the whole point -
 * a yes here has to mean a yes there. A condition added to either side belongs to the
 * other one as well.
 *
 * The parity holds of a no as well, and the two noes cost the asker opposite things. A
 * portal the transfer has not been rolled out to yet is refused by a word that side waits
 * on {@see MigrationService::ERROR_OPERATION_SCHEMA_MISSING}, and a readiness that could
 * not be established at all by a word that side pays an attempt for
 * {@see MigrationService::ERROR_READINESS_UNANSWERED}. Both belong to the vocabulary of
 * the source generation rather than to this class: that is the one their own guard reads.
 */
final class HandoverReadiness
{
	/**
	 * The services are built inside the question rather than handed to the constructor of
	 * it, so that a failure of building them is a refusal like any other. Either of them
	 * may still be passed in, which is how a test stands a portal in a state of its own.
	 */
	public function __construct(
		private readonly ?MigrationService $migrationService = null,
		private readonly ?MigrationOperationSchema $operationSchema = null,
	)
	{
	}

	public function check(): Result
	{
		$result = new Result();

		/*
			The caller asks a question about readiness and holds no password yet: everything
			the answer cannot be given by is a no of this side, not a failure thrown at the
			one who asked. An incomplete schema announces itself by an exception, and so does
			anything the services of the question break on while being built or read.
		*/
		try
		{
			$refusal = ($this->migrationService ?? new MigrationService())->findPortalRefusal();
			if ($refusal !== null)
			{
				return $result->addError($refusal);
			}

			if (($this->operationSchema ?? new MigrationOperationSchema())->isReady())
			{
				return $result;
			}
		}
		catch (\Throwable)
		{
			return $result->addError(new Error(
				'The readiness of the portal for a hand-over cannot be established',
				MigrationService::ERROR_READINESS_UNANSWERED,
			));
		}

		return $result->addError(new Error(
			'The migration operation schema of the portal is not built yet',
			MigrationService::ERROR_OPERATION_SCHEMA_MISSING,
		));
	}
}
