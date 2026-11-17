<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add;

use Bitrix\Tasks\Control\Exception\TemplateAddException;
use Bitrix\Tasks\Control\Handler\Exception\TemplateFieldValidateException;
use Bitrix\Tasks\V2\Internal\Service\Trait\ApplicationErrorTrait;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PrepareBaseTemplate;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PrepareBBCodes;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PrepareCreator;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PrepareDependencies;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PrepareDescription;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PrepareMembers;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PrepareMultitask;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PrepareParentId;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PreparePipeline;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PreparePriority;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PrepareReplication;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PrepareResponsible;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PrepareSiteId;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PrepareTags;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PrepareTitle;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare\PrepareType;
use Bitrix\Tasks\V2\Internal\Service\Template\Author\AuthorAssignmentSubject;

class PrepareFields
{
	use ApplicationErrorTrait;

	public function __construct(
		private readonly ?AuthorAssignmentSubject $authorSubject = null,
	)
	{
	}

	public function __invoke(array $fields): array
	{
		try
		{
			$fields = $this->prepareFields($fields);
		}
		catch (TemplateFieldValidateException $e)
		{
			$message = $e->getMessage();

			$this->setApplicationError($message);

			throw new TemplateAddException($e->getMessage(), previous: $e);
		}

		return $fields;
	}

	private function prepareFields(array $fields): array
	{
		$pipeline = new PreparePipeline([
			PrepareType::class,
			new PrepareCreator($this->authorSubject),
			PrepareResponsible::class,
			PrepareMultitask::class,
			PrepareBBCodes::class,
			PrepareMembers::class,
			PrepareTitle::class,
			PrepareReplication::class,
			PrepareBaseTemplate::class,
			PrepareParentId::class,
			PreparePriority::class,
			PrepareSiteId::class,
			PrepareTags::class,
			PrepareDependencies::class,
			PrepareDescription::class,
		]);

		return $pipeline($fields);
	}
}
