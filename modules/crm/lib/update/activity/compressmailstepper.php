<?php

namespace Bitrix\Crm\Update\Activity;

use Bitrix\Main;
use Bitrix\Crm;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Main\Config\Option;

/**
 * Class CompressMailStepper
 *
 * <code>
 * \Bitrix\Main\Update\Stepper::bindClass('Bitrix\Crm\Update\Activity\CompressMailStepper', 'crm');
 * </code>
 *
 * @package Bitrix\Crm\Update
 */
final class CompressMailStepper extends Main\Update\Stepper
{
	public const COMPRESS_IN_PROGRESS_OPTION_NAME = 'compress_mail_act_stepper_in_progress';

	protected static $moduleId = 'crm';

	public function execute(array &$option)
	{
		//return self::FINISH_EXECUTION; -- ON EMERGENCY
		if (Option::get(self::$moduleId, self::COMPRESS_IN_PROGRESS_OPTION_NAME, 'N') !== 'Y')
		{
			Option::set(self::$moduleId, self::COMPRESS_IN_PROGRESS_OPTION_NAME, 'Y');
		}

		$lastId = (int)($option['lastId'] ?? 0);

		$maxResult = \Bitrix\Crm\ActivityTable::getList([
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
			'select' => ['ID'],
		])->fetch();

		$maxID = (int)$maxResult['ID'];
		if ($lastId >= $maxID)
		{
			Option::delete(self::$moduleId, ['name' => self::COMPRESS_IN_PROGRESS_OPTION_NAME]);

			return self::FINISH_EXECUTION;
		}

		$listIDResult = \Bitrix\Crm\ActivityTable::getList([
			'order' => ['ID' => 'ASC'],
			'limit' => $this->getLimit(),
			'filter' => ['>ID' => $lastId],
			'select' => ['ID', 'TYPE_ID', 'PROVIDER_TYPE_ID', 'ASSOCIATED_ENTITY_ID'],
		]);

		$ids = [];
		$compressedMailIds = [];
		foreach ($listIDResult as $row)
		{
			$row['ASSOCIATED_ENTITY_ID'] = (int)$row['ASSOCIATED_ENTITY_ID'];
			$row['TYPE_ID'] = (int)$row['TYPE_ID'];

			if ($row['TYPE_ID'] === \CCrmActivityType::Email)
			{
				$ids[] = $row['ID'];
				if (
					$row['PROVIDER_TYPE_ID'] === Email::TYPE_EMAIL_COMPRESSED
					?? $row['ASSOCIATED_ENTITY_ID'] > 0
				)
				{
					$compressedMailIds[] = $row['ID'];
				}
			}

			$option['lastId'] = $row['ID'];
		}

		if (!empty($ids))
		{
			$listResult = \Bitrix\Crm\ActivityTable::getList([
				'order' => ['ID' => 'ASC'],
				'filter' => ['@ID' => $ids],
				'select' => ['ID', 'DESCRIPTION', 'DESCRIPTION_TYPE', 'ASSOCIATED_ENTITY_ID', 'PROVIDER_PARAMS'],
			]);

			foreach ($listResult as $row)
			{
				$id = $row['ID'];

				if (in_array($id, $compressedMailIds, true))
				{
					Crm\Activity\Entity\ActMailBodyBindTable::add([
						'BODY_ID' => $row['ASSOCIATED_ENTITY_ID'],
						'OWNER_TYPE_ID' => \CCrmOwnerType::Activity,
						'OWNER_ID' => $id,
					]);
					Crm\ActivityTable::update(
						$id,
						[
							'ASSOCIATED_ENTITY_ID' => 0,
						],
					);
				}
				else
				{
					$originalDescription = $row['DESCRIPTION'];

					Email::compressActivityDescription($row);
					Crm\ActivityTable::update(
						$id,
						[
							'DESCRIPTION' => $row['DESCRIPTION'],
							'DESCRIPTION_TYPE' => $row['DESCRIPTION_TYPE'],
							'PROVIDER_TYPE_ID' => $row['PROVIDER_TYPE_ID'],
						],
					);
					Email::addMailBodyBinding($id, \CCrmOwnerType::Activity, $originalDescription);
				}
			}
		}

		return self::CONTINUE_EXECUTION;
	}

	private function getLimit(): int
	{
		return (int)Main\Config\Option::get('crm',  'compress_mail_act_step_limit', 50);
	}
}
