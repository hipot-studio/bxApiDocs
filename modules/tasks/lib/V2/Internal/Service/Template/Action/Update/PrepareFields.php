<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update;

use Bitrix\Tasks\Control\Exception\TemplateAddException;
use Bitrix\Tasks\Control\Handler\Exception\TemplateFieldValidateException;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PrepareDiskAttachments;
use Bitrix\Tasks\V2\Internal\Service\Trait\ApplicationErrorTrait;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PrepareBaseTemplate;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PrepareBBCodes;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PrepareDependencies;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PrepareDescription;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PrepareMembers;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PrepareMultitask;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PrepareParentId;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PreparePipeline;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PreparePriority;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PrepareReplication;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PrepareResponsible;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PrepareSiteId;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PrepareTags;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PrepareTitle;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare\PrepareType;

class PrepareFields
{
	use ApplicationErrorTrait;

	public function __invoke(array $fields, array $fullTemplateData): array
	{
		try
		{
			$fields = $this->prepareFields($fields, $fullTemplateData);
		}
		catch (TemplateFieldValidateException $e)
		{
			$message = $e->getMessage();

			$this->setApplicationError($message);

			throw new TemplateAddException($e->getMessage());
		}

		return $fields;
	}

	private function prepareFields(array $fields, array $fullTemplateData): array
	{
		$pipeline = new PreparePipeline([
			PrepareResponsible::class,
			PrepareMultitask::class,
			PrepareBBCodes::class,
			PrepareType::class,
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
			PrepareDiskAttachments::class,
		]);

		return $pipeline($fields, $fullTemplateData);
	}
}
