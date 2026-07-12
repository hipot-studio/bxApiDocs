<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Filter\Project\Provider;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\V2\Infrastructure\Filter\Provider\BaseFilterDataProvider;

class FilterDataProvider extends BaseFilterDataProvider
{
	/**
	 * @throws ArgumentException
	 */
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
			$result['PROJECT_DATE'] = $this->createField('PROJECT_DATE', [
				'name' => $this->getSharedFieldName('PROJECT_DATE'),
				'type' => 'date',
				'default' => false,
				'time' => false,
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

		$result['COUNTERS'] = $this->createField('COUNTERS', [
			'name' => $this->getSharedFieldName('COUNTERS'),
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

		if ($fieldID === 'COUNTERS')
		{
			return [
				'items' => [
					'EXPIRED' => Loc::getMessage('SONET_V2_FILTER_PROJECT_COUNTERS_EXPIRED'),
					'NEW_COMMENTS' => $this->getSharedMessage('COUNTERS_NEW_COMMENTS'),
				],
			];
		}

		return null;
	}
}
