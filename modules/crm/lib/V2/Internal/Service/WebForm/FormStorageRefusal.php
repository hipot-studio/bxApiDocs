<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormPath;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormValidationException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;

/**
 * Turns an unsuccessful write of a CRM form into the one error format the caller understands.
 *
 * The storage writes what the caller sent and nothing else, so its refusals are refusals of that
 * input and belong to the input paths. Every writer answers with a code this class can read as such
 * a path: the section writers name their column or their section, and the writer of the ad links
 * addresses to the section of the input the refusals of the mechanism owning them, which answers
 * with no code at all.
 *
 * The text of the storage stays inside and goes to the log instead: the ORM and the legacy options
 * write about the columns they refused, and the shape of the schema is not part of the public
 * contract. The path of the input is what the caller acts on anyway.
 *
 * Both scenarios writing a form hand their refusals here - the write of the configuration
 * ({@see FormWriter}) and the switch of the activity ({@see FormActivator}) - so one refusal of the
 * storage reaches the client as one answer rather than as two.
 *
 * @internal
 */
final class FormStorageRefusal
{
	/** What a refusal of the storage says instead of its own text. */
	private const MESSAGE = 'Value of this field was not accepted by the CRM form';

	public function __construct(private readonly LoggerInterface $logger)
	{
	}

	/**
	 * @param int $formId the form a refused creation had already written. It travels on the refusal
	 *        itself rather than inside the errors: which form exists is one fact about the operation,
	 *        not a property of any field of the input.
	 */
	public function toException(Result $result, int $formId = 0): FormValidationException
	{
		$errors = [];
		foreach ($result->getErrors() as $error)
		{
			$path = self::toInputPath((string)$error->getCode());
			$this->log($path, (string)$error->getMessage());

			$errors[] = new Error(self::MESSAGE, $path, $error->getCustomData());
		}

		return $formId > 0
			? FormValidationException::ofPartiallyCreatedForm($formId, ...$errors)
			: new FormValidationException(...$errors)
		;
	}

	private function log(string $path, string $message): void
	{
		$this->logger->error(
			'CRM form write was refused by the storage at `{path}`: {message}',
			['path' => $path, 'message' => $message],
		);
	}

	/**
	 * The input path a code of the storage stands for. A writer of a section whose input is one
	 * collection names the section it failed on, and the collection is the address of that input;
	 * anything else already is a path, a column of the form row included.
	 */
	private static function toInputPath(string $code): string
	{
		if ($code === WebForm::SECTION_INTEGRATIONS)
		{
			return WebFormPath::ADS;
		}

		if ($code === WebForm::SECTION_RESPONSIBLE)
		{
			return WebFormPath::RESPONSIBLE_USER_IDS;
		}

		if ($code === WebForm::SECTION_AGREEMENTS)
		{
			return WebFormPath::AGREEMENT_ITEMS;
		}

		return $code;
	}
}
