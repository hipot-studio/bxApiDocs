<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Filter\Provider;

use Bitrix\Main\Filter\EntityDataProvider;
use Bitrix\Main\Filter\Field;
use Bitrix\Main\Localization\Loc;

use Bitrix\Bizproc\Internal\Grid\AiAgents\Filter\AiAgentsFilterSettings;

class AiAgentsDataProvider extends EntityDataProvider
{
	private AiAgentsFilterSettings $settings;

	public function __construct(AiAgentsFilterSettings $settings)
	{
		$this->settings = $settings;
	}

	public function getSettings(): AiAgentsFilterSettings
	{
		return $this->settings;
	}

	/**
	 * @param string $fieldID Field ID.
	 */
	public function prepareFieldData($fieldID): ?array
	{
		if ($fieldID === 'LAUNCHED_BY')
		{
			return [
				'params' => [
					'multiple' => 'Y',
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

		return null;
	}

	/**
	 * @param string $fieldID Field ID.
	 */
	protected function getFieldName($fieldID): string
	{
		if (!is_string($fieldID))
		{
			return '';
		}

		return match ($fieldID)
		{
			'LAUNCHED_BY' => Loc::getMessage("BIZPROC_AI_AGENTS_COLUMN_LAUNCHED_BY") ?? '',
			default => $fieldID,
		};
	}

	/**
	 * @return array<string, Field>
	 */
	public function prepareFields(): array
	{
		$result = [];

		$result['LAUNCHED_BY'] = $this->createField('LAUNCHED_BY', [
			'type' => 'entity_selector',
			'default' => true,
			'partial' => true,
		]);

		return $result;
	}
}
