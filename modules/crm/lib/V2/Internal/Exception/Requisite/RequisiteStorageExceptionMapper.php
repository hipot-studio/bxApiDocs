<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Requisite;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Error;
use Psr\Log\LoggerInterface;

/**
 * Turns whatever the storage boundary of the requisite domain refuses with into one domain error, and
 * makes sure the refusal itself ends up in the log rather than in the answer.
 *
 * The boundary of this area refuses in exactly one shape - a {@see \Bitrix\Main\SystemException} carrying
 * the texts of the refusal verbatim
 * ({@see \Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteWriteRepository}) - and the same is done
 * with whatever else is thrown inside the transaction: an argument the boundary will not take, a failure
 * of a neighbouring cascade, a failure of the connection. All of them are one thing to the caller: the
 * write did not happen, and nothing partial is left of it.
 *
 * **Nothing is recognized by its text, on purpose.** Every everyday refusal a client can cause is refused
 * before the transaction opens - by the permissions, by the resolver of the owner or by {@see
 * \Bitrix\Crm\V2\Internal\Service\Requisite\RequisiteFieldValidator}, each of them naming the field it is
 * about. What reaches this class is therefore what those checks did not foresee, and its text is the text
 * of the ORM, of a validator of the legacy table or of the database: localized, rewritten by translators,
 * and naming columns, classes and constraints. Reading a field name back out of such a text is exactly
 * what the refusals of legacy make unreliable, and guessing one from "there is a single candidate today"
 * is that same guess with more steps. So the answer carries a code and no text, and the log carries the
 * original together with what the operation was about.
 *
 * @internal
 */
final class RequisiteStorageExceptionMapper
{
	/**
	 * The storage would not write the requisite, and the domain cannot say more than that. The phrase of
	 * it belongs to the published errors of the domain, so the error carries no message.
	 */
	public const ERROR_STORAGE_REFUSED = 'REQUISITE_OPERATION_FAILED';

	private const LOGGER_ID = 'Default';

	public function __construct(private readonly ?LoggerInterface $logger = null)
	{
	}

	/**
	 * @param array<string, int|string|null> $subject what the operation was about - the requisite, its
	 *        owner, its preset - so that the log entry can be tied to a record without the answer
	 *        carrying any of it.
	 */
	public function map(\Throwable $refusal, array $subject = []): Error
	{
		// The refusal is described rather than handed over as an object: the logger of the module keeps
		// the context of an entry in a row of its own ({@see \Bitrix\Crm\Service\Logger\DbLogger}), and
		// the ORM clones the context on the way there - which an exception does not survive.
		$this->resolveLogger()->error(
			'CRM requisite domain got a storage refusal it cannot classify: {message}',
			[
				'message' => $refusal->getMessage(),
				'refusal' => sprintf(
					'%s at %s:%d',
					$refusal::class,
					$refusal->getFile(),
					$refusal->getLine(),
				),
				'subject' => $subject,
			],
		);

		return new Error('', self::ERROR_STORAGE_REFUSED);
	}

	private function resolveLogger(): LoggerInterface
	{
		return $this->logger ?? Container::getInstance()->getLogger(self::LOGGER_ID);
	}
}
