<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\Control\Handler\Exception\TaskFieldValidateException;
use Bitrix\Tasks\Control\Handler\TariffFieldHandler;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareChangedBy;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareCreator;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareDatePlan;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareDates;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareDeadline;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareDescription;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareDiskAttachments;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareFlags;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareFlow;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareGroup;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareGuid;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareId;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareIntegration;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareMark;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareMembers;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareOutlook;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareParents;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PreparePipeline;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PreparePriority;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareSiteId;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareStage;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareStatus;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareTags;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare\PrepareTitle;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Trait\ApplicationErrorTrait;

class PrepareFields
{
	use ConfigTrait;
	use ApplicationErrorTrait;

	public function __invoke(array $fields): array
	{
		// Fallback: record whether the client set a deadline BEFORE the
		// prepare pipeline runs. PrepareDeadline (below) substitutes the user's
		// default auto deadline into DEADLINE when none is given, after which a
		// manually set and an automatically applied deadline are indistinguishable
		// downstream. This pre-pipeline value is the FALLBACK source for the
		// onboarding "days without a manual deadline" counter: it is carried to
		// OnTaskAdd via RuntimeData (read in RunAddEvent) and used only when the
		// caller did not send the explicit AddConfig::getManualDeadline() marker
		// (mobile app, legacy CTaskItem, REST). The explicit marker takes priority.
		$this->config->getRuntime()->setClientDeadlineProvided(!empty($fields['DEADLINE']));

		try
		{
			$fields = $this->prepareFields($fields);
		}
		catch (TaskFieldValidateException $e)
		{
			$message = $e->getMessage();

			$this->setApplicationError($message);

			throw new TaskAddException($e->getMessage());
		}

		return $fields;
	}

	private function prepareFields(array $fields): array
	{
		$pipeline = new PreparePipeline($this->config, [
			PrepareFlow::class,
			PrepareGuid::class,
			PrepareSiteId::class,
			PrepareGroup::class,
			PrepareStage::class,
			PrepareCreator::class,
			PrepareTitle::class,
			PrepareDescription::class,
			PrepareStatus::class,
			PreparePriority::class,
			PrepareMark::class,
			PrepareFlags::class,
			PrepareParents::class,
			PrepareMembers::class,
			PrepareOutlook::class,
			PrepareTags::class,
			PrepareChangedBy::class,
			PrepareDeadline::class,
			PrepareDates::class,
			PrepareId::class,
			PrepareIntegration::class,
			PrepareDiskAttachments::class,
			PrepareDatePlan::class,
		]);

		return $pipeline($fields);
	}
}
