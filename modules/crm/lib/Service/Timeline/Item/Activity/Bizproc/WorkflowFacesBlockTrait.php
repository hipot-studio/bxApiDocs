<?php

namespace Bitrix\Crm\Service\Timeline\Item\Activity\Bizproc;

use Bitrix\Bizproc\Api\Data\WorkflowFacesService\ProgressBox;
use Bitrix\Bizproc\Api\Data\WorkflowFacesService\Step;
use Bitrix\Bizproc\Api\Enum\WorkflowFacesService\WorkflowFacesStep;
use Bitrix\Bizproc\Api\Enum\WorkflowFacesService\WorkflowFacesStepStatus;
use Bitrix\Bizproc\Api\Service\WorkflowFacesService;
use Bitrix\Crm\Service\Timeline\Layout\Body;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\AvatarsStackSteps;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

trait WorkflowFacesBlockTrait
{
	protected function getAvatarsStackSteps(array $faces): ?Body\ContentBlock\AvatarsStackSteps\AvatarsStackSteps
	{
		if (
			!isset($faces['steps'])
			|| !Loader::includeModule('bizproc')
			|| !enum_exists('\Bitrix\Bizproc\Api\Enum\WorkflowFacesService\WorkflowFacesStep')
		)
		{
			return null;
		}

		$stack =
			(new Body\ContentBlock\AvatarsStackSteps\AvatarsStackSteps())
				->setStyles(['minWidth' => 273])
		;
		$stepCount = 0;
		foreach ($faces['steps'] as $stepData)
		{
			$step = $this->getStep("step-$stepCount", $stepData);
			if ($step)
			{
				$stack->addStep($step);
				$stepCount++;
			}
		}

		if ($stepCount < 3) // minimum 3 steps
		{
			for ($i = $stepCount; $i < 3; $i++)
			{
				$stack->addStep(
					(new AvatarsStackSteps\Step("step-$i"))
						->setStyles(['minWidth' => 75])
				);
			}
		}

		if (isset($faces['progressTasksCount']) && $faces['progressTasksCount'] > 0)
		{
			$steps = $stack->getSteps();
			$firstStep = current($steps);
			$firstStep->setProgressBoxTitle((new ProgressBox($faces['progressTasksCount']))->getFormattedText());
		}

		return $stack;
	}

	private function getStep(string $stepId, array $data): ?AvatarsStackSteps\Step
	{
		$step = WorkflowFacesService::getStepById($data['id']);
		if (!$step)
		{
			return null;
		}

		$step->fillFromData($data);

		$stepBlock =
			(new AvatarsStackSteps\Step($stepId))
				->setHeaderTitle($step->getName())
		;

		$this->fillAvatars($stepBlock, $step);
		$this->fillStatus($stepBlock, $step);
		$this->fillDuration($stepBlock, $step);

		$stepBlock->setStyles(['minWidth' => 75]);

		return $stepBlock;
	}

	private function fillAvatars(AvatarsStackSteps\Step $stepBlock, Step $step): void
	{
		$avatars = $step->getAvatars();
		if ($avatars)
		{
			$autoDecisions = method_exists($step, 'getAutoDecisions') ? $step->getAutoDecisions() : [];
			$stepBlock->setAvatars($this->getFacesAvatars($avatars, $autoDecisions));

			return;
		}

		if ($step->getId() === WorkflowFacesStep::Completed->value)
		{
			return;
		}

		$icon = AvatarsStackSteps\Enum\Icon::BP;
		$color = AvatarsStackSteps\Enum\IconColor::LightGrey;

		if ($step->getId() === WorkflowFacesStep::Running->value)
		{
			$icon = AvatarsStackSteps\Enum\Icon::BlackClock;
			$color = AvatarsStackSteps\Enum\IconColor::Blue;
		}

		if ($step->getId() === WorkflowFacesStep::Done->value)
		{
			$icon = AvatarsStackSteps\Enum\Icon::CircleCheck;
			$color = AvatarsStackSteps\Enum\IconColor::LightGreen;
		}

		$stepBlock->setIcon($icon, $color);
	}

	private function getFacesAvatars(array $userIds, array $autoDecisions = []): array
	{
		$avatars = [];
		foreach ($userIds as $userId)
		{
			$avatar = ['src' => $this->getUserSrcById($userId), 'id' => $userId];

			$badge = $this->getAutoDecisionBadge($autoDecisions[(int)$userId] ?? null);
			if ($badge)
			{
				$avatar['badge'] = $badge;
			}

			$avatars[] = $avatar;
		}

		return $avatars;
	}

	private function getAutoDecisionBadge(mixed $autoDecision): ?array
	{
		$code = match ($autoDecision)
		{
			'yes' => 'CRM_TIMELINE_BIZPROC_FACES_AUTO_APPROVED',
			'no' => 'CRM_TIMELINE_BIZPROC_FACES_AUTO_REJECTED',
			default => null,
		};

		$title = $code ? (string)Loc::getMessage($code) : '';
		if ($title === '')
		{
			return null;
		}

		return ['icon' => 's-robot', 'title' => $title];
	}

	private function getUserSrcById(int $userId): string
	{
		if ($userId <= 0)
		{
			return '';
		}

		$user = \Bitrix\Crm\Service\Container::getInstance()->getUserBroker()->getById($userId);
		if (!is_array($user) || !isset($user['PHOTO_URL']))
		{
			return '';
		}

		return (string)($user['PHOTO_URL']);
	}

	private function fillDuration(AvatarsStackSteps\Step $stepBlock, Step $step): void
	{
		$stepBlock->setFooterTitle($step::getEmptyDurationText());

		$duration = $step->getDuration();
		if ($duration !== 0)
		{
			$stepBlock->setDurationFooter($duration);
		}
	}

	private function fillStatus(AvatarsStackSteps\Step $stepBlock, Step $step): void
	{
		$status = $step->getStatus();
		if ($status && $step->getAvatars())
		{
			if ($status === WorkflowFacesStepStatus::Wait)
			{
				$stepBlock->setStatus(AvatarsStackSteps\Enum\StackStatus::Wait);
			}
			else
			{
				$stepBlock->setStatus(
					$status === WorkflowFacesStepStatus::Success
						? AvatarsStackSteps\Enum\StackStatus::Ok
						: AvatarsStackSteps\Enum\StackStatus::Cancel
				);
			}
		}
	}
}
