<?php
namespace Bitrix\Tasks\TourGuide;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\Query\Join;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\WorkgroupTable;
use Bitrix\Tasks\TourGuide\Exception\TourGuideException;
use Exception;

Loc::loadMessages(__FILE__);

/**
 * Class FirstProjectCreation
 *
 * @package Bitrix\Tasks\TourGuide
 */
class FirstProjectCreation extends TourGuide
{
	protected const OPTION_NAME = 'firstProjectCreation';

	/**
	 * @throws TourGuideException
	 */
	public function proceed(): bool
	{
		try
		{
			if ($this->isFinished() || $this->isInLocalSession())
			{
				return false;
			}

			if ($this->isGroupExist())
			{
				return false;
			}

			if (($currentStep = $this->getCurrentStep()) && !$currentStep->isFinished())
			{
				$currentStep->makeTry();

				if ($currentStep->isFinished() && $this->isCurrentStepTheLast())
				{
					$this->finish();
					return true;
				}

				$this->saveToLocalSession();
				$this->saveData();

				return true;
			}

			return false;
		}
		catch (Exception $exception)
		{
			throw new TourGuideException($exception->getMessage());
		}
	}

	public function isFirstExperience(): bool
	{
		return true;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	private function isGroupExist(): bool
	{
		$query = WorkgroupTable::query();
		$query
			->setSelect(['ID'])
			->setLimit(1)
			->where('VISIBLE', 'Y')
		;

		if ($query->exec()->fetch())
		{
			return true;
		}

		$query = WorkgroupTable::query();
		$query
			->setSelect(['ID'])
			->setLimit(1)
			->registerRuntimeField(
				'UG',
				new ReferenceField(
					'UG',
					UserToGroupTable::getEntity(),
					Join::on('this.ID', 'ref.GROUP_ID')
						->where('ref.USER_ID', $this->getUserId())
						->whereIn('ref.ROLE', UserToGroupTable::getRolesMember())
					,
					['join_type' => 'inner']
				)
			)
		;

		return (bool)$query->exec()->fetch();
	}

	protected function getDefaultSteps(): array
	{
		return [
			[
				'maxTriesCount' => 3,
				'currentTry' => 0,
				'isFinished' => false,
				'additionalData' => [],
			],
		];
	}

	protected function loadPopupData(): array
	{
		$prefix = 'TASKS_TOUR_GUIDE_FIRST_PROJECT_CREATION';

		return [
			[
				[
					'title' => Loc::getMessage("{$prefix}_POPUP_0_TITLE"),
					'text' => Loc::getMessage("{$prefix}_POPUP_0_TEXT"),
					'article' => 13557096,
				],
			],
		];
	}
}
