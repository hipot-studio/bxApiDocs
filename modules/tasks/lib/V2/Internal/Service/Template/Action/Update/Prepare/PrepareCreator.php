<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

use Bitrix\Tasks\Control\Handler\Exception\TemplateFieldValidateException;
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

	public function __invoke(array $fields, array $fullTemplateData): array
	{
		if (!array_key_exists('TPARAM_TYPE', $fullTemplateData))
		{
			throw new TemplateFieldValidateException('Unknown template type id passed');
		}

		if ((int)$fullTemplateData['TPARAM_TYPE'] !== TypeDictionary::NEW_USERS)
		{
			return $fields;
		}

		if (!array_key_exists('CREATED_BY', $fields))
		{
			return $fields;
		}

		$creatorId = (int)$fields['CREATED_BY'];
		if ($creatorId === (int)($fullTemplateData['CREATED_BY'] ?? 0))
		{
			unset($fields['CREATED_BY']);

			return $fields;
		}

		$result = $this->policy->check($this->subject, $creatorId);
		if (!$result->isSuccess())
		{
			throw AuthorAssignmentException::fromError($result->getError());
		}

		return $fields;
	}
}
