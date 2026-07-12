<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Filter\Project\Provider;

use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\V2\Infrastructure\Filter\Provider\BaseFilterDataProvider;

class CommonProjectFilterDataProvider extends BaseFilterDataProvider
{
	public function prepareFields(): array
	{
		$result = [];

		$result['ID'] = $this->createField('ID', [
			'name' => 'ID',
			'type' => 'number',
			'default' => false,
		]);

		$result['NAME'] = $this->createField('NAME', [
			'name' => $this->getSharedFieldName('NAME'),
			'default' => true,
		]);

		$result['OWNER'] = $this->createField('OWNER', [
			'name' => $this->getSharedOwnerFieldName(),
			'type' => 'entity_selector',
			'default' => false,
			'partial' => true,
		]);

		$result['MEMBER'] = $this->createField('MEMBER', [
			'name' => $this->getSharedFieldName('MEMBER'),
			'type' => 'entity_selector',
			'default' => false,
			'partial' => true,
		]);

		$result['TAG'] = $this->createField('TAG', [
			'name' => $this->getSharedFieldName('TAG'),
			'default' => false,
		]);

		$result['VISIBLE'] = $this->createField('VISIBLE', [
			'name' => $this->getSharedFieldName('VISIBLE'),
			'type' => 'checkbox',
			'default' => false,
		]);

		$result['OPENED'] = $this->createField('OPENED', [
			'name' => $this->getSharedFieldName('OPENED'),
			'type' => 'checkbox',
			'default' => false,
		]);

		if ($this->isClosedFieldAvailable())
		{
			$result['CLOSED'] = $this->createField('CLOSED', [
				'name' => $this->getSharedFieldName('CLOSED'),
				'type' => 'checkbox',
				'default' => false,
			]);
		}

		if ($this->isProjectFieldAvailable())
		{
			$result['PROJECT'] = $this->createField('PROJECT', [
				'name' => Loc::getMessage('SONET_V2_FILTER_COMMON_PROJECT_COLUMN_PROJECT'),
				'type' => 'checkbox',
				'default' => false,
			]);

			$result['PROJECT_DATE'] = $this->createField('PROJECT_DATE', [
				'name' => $this->getSharedFieldName('PROJECT_DATE'),
				'type' => 'date',
				'default' => false,
				'time' => false,
			]);
		}

		if ($this->isScrumFieldAvailable())
		{
			$result['SCRUM'] = $this->createField('SCRUM', [
				'name' => Loc::getMessage('SONET_V2_FILTER_COMMON_PROJECT_COLUMN_SCRUM'),
				'type' => 'checkbox',
				'default' => false,
			]);
		}

		if ($this->isExtranetFieldAvailable())
		{
			$result['EXTRANET'] = $this->createField('EXTRANET', [
				'name' => $this->getSharedFieldName('EXTRANET'),
				'type' => 'list',
				'default' => false,
				'partial' => true,
			]);
		}

		if ($this->isLandingFieldAvailable())
		{
			$result['LANDING'] = $this->createField('LANDING', [
				'name' => $this->getSharedFieldName('LANDING'),
				'type' => 'checkbox',
				'default' => false,
			]);
		}

		$result['FAVORITES'] = $this->createField('FAVORITES', [
			'name' => $this->getSharedFieldName('FAVORITES'),
			'type' => 'list',
			'default' => false,
			'partial' => true,
		]);

		return $result;
	}

	public function prepareFieldData($fieldID): ?array
	{
		if (in_array($fieldID, ['OWNER', 'MEMBER'], true))
		{
			return [
				'params' => [
					'multiple' => 'N',
					'dialogOptions' => [
						'context' => 'filter',
						'entities' => [
							[
								'id' => 'user',
								'dynamicLoad' => true,
								'dynamicSearch' => true,
							],
						],
					],
				],
			];
		}

		if (in_array($fieldID, ['FAVORITES', 'EXTRANET'], true))
		{
			return [
				'items' => $this->getSharedListItems(),
			];
		}

		return null;
	}
}
