<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Requisite\Exception;

use Bitrix\Crm\V2\Public\Entity\Requisite\RequisiteError;
use Bitrix\Main\ObjectNotFoundException;

/**
 * The single absence answer of the reads of the requisite domain: the object the caller addressed is not
 * one this user gets.
 *
 * ONE ANSWER FOR THREE CAUSES, AND THAT IS THE CONTRACT. The object may be gone, it may be there and
 * hidden from this user by the permissions of its owner, or it may belong to an owner other than the one
 * the caller addressed. All three are this exception, and nothing about it says which happened - a read
 * that told them apart would let a client learn by trying which requisites and which counterparties a
 * portal keeps.
 *
 * Which object was absent **is** said, by {@see self::getError()}: a refusal about the requisite, about
 * its owner or about a preset is a different thing to report, and the transport above classifies by that
 * code. The three causes stay indistinguishable within each of them.
 *
 * The reads of the domain refuse by throwing rather than by answering an empty value: a requisite that is
 * not there and a requisite whose every field is empty are both nothing to look at, and a page of an
 * owner that is not there is not an empty page.
 */
final class NotFoundException extends ObjectNotFoundException
{
	public function __construct(private readonly RequisiteError $error)
	{
		parent::__construct($error->getMessage());
	}

	/**
	 * What was absent, as a code of the domain: {@see RequisiteError::REQUISITE_NOT_FOUND},
	 * {@see RequisiteError::OWNER_NOT_FOUND} or {@see RequisiteError::PRESET_NOT_FOUND}.
	 */
	public function getError(): RequisiteError
	{
		return $this->error;
	}

	/** The same code as a string, for a consumer that carries codes rather than cases. */
	public function getErrorCode(): string
	{
		return $this->error->value;
	}
}
