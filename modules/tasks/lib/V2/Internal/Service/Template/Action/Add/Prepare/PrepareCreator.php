<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare;

use Bitrix\Tasks\V2\Internal\Entity\Template\TypeDictionary;
use Bitrix\Tasks\V2\Internal\Service\Template\Author\AuthorAssignmentException;
use Bitrix\Tasks\V2\Internal\Service\Template\Author\AuthorAssignmentPolicy;
use Bitrix\Tasks\V2\Internal\Service\Template\Author\AuthorAssignmentSubject;

class PrepareCreator implements PrepareFieldInterface
{
	public function __construct(
		private readonly ?AuthorAssignmentSubject $subject,
		private readonly AuthorAssignmentPolicy $policy = new AuthorAssignmentPolicy(),
	)
	{
	}

	public function __invoke(array $fields): array
	{
		if ((int)($fields['TPARAM_TYPE'] ?? 0) !== TypeDictionary::NEW_USERS)
		{
			return $fields;
		}

		$creatorId = array_key_exists('CREATED_BY', $fields)
			? (int)$fields['CREATED_BY']
			: ($this->subject?->getUserId() ?? 0)
		;

		$result = $this->policy->check($this->subject, $creatorId);
		if (!$result->isSuccess())
		{
			throw AuthorAssignmentException::fromError($result->getError());
		}

		$fields['CREATED_BY'] = $creatorId;

		return $fields;
	}
}
