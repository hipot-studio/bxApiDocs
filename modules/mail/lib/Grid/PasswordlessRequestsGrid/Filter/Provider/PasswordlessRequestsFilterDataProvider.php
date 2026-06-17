<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid\Filter\Provider;

use Bitrix\Mail\Grid\PasswordlessRequestsGrid\Filter\PasswordlessRequestsFilterSettings;
use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Helper\Enum\PasswordlessRequestField;
use Bitrix\Main\Filter\EntityDataProvider;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Filter\DateType;

class PasswordlessRequestsFilterDataProvider extends EntityDataProvider
{
	private PasswordlessRequestsFilterSettings $settings;

	public function __construct(PasswordlessRequestsFilterSettings $settings)
	{
		$this->settings = $settings;
	}

	public function getSettings(): PasswordlessRequestsFilterSettings
	{
		return $this->settings;
	}

	/**
	 * @param string $fieldID Field ID.
	 * @return ?array{
	 *     params: array{
	 *         apiVersion: int,
	 *         context: string,
	 *         multiple: string,
	 *         contextCode: string,
	 *         enableDepartments: string,
	 *         enableAll: string,
	 *         enableUsers: string,
	 *         enableSonetgroups: string,
	 *         allowEmailInvitation: string,
	 *         allowSearchEmailUsers: string,
	 *         departmentSelectDisable: string,
	 *         isNumeric: string,
	 *     },
	 * }|array{
	 *     items: array<string, string>,
	 * }|null
	 */
	public function prepareFieldData($fieldID): array|null
	{
		$field = PasswordlessRequestField::tryFrom($fieldID);

		if ($field === PasswordlessRequestField::Employee)
		{
			return [
				'params' => [
					'apiVersion' => 3,
					'context' => 'PASSWORDLESS_REQUESTS_FILTER_EMPLOYEE',
					'multiple' => 'Y',
					'contextCode' => 'U',
					'enableDepartments' => 'N',
					'enableAll' => 'N',
					'enableUsers' => 'Y',
					'enableSonetgroups' => 'N',
					'allowEmailInvitation' => 'N',
					'allowSearchEmailUsers' => 'N',
					'departmentSelectDisable' => 'Y',
					'isNumeric' => 'N',
				],
			];
		}

		if ($field === PasswordlessRequestField::Status)
		{
			return [
				'items' => [
					MailboxStatus::Pending->value => Loc::getMessage('MAIL_PASSWORDLESS_GRID_FILTER_STATUS_PENDING') ?? '',
					MailboxStatus::Canceled->value => Loc::getMessage('MAIL_PASSWORDLESS_GRID_FILTER_STATUS_CANCELED') ?? '',
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
		$name = Loc::getMessage("MAIL_PASSWORDLESS_GRID_FILTER_{$fieldID}");

		return $name ?? $fieldID;
	}

	/**
	 * @return \Bitrix\Main\Filter\Field[]
	 */
	public function prepareFields(): array
	{
		$result = [];

		$fieldsList = [
			PasswordlessRequestField::Employee->value => [
				'options' => ['default' => true, 'type' => 'dest_selector', 'partial' => true],
			],
			PasswordlessRequestField::Email->value => [
				'options' => ['default' => true],
			],
			PasswordlessRequestField::Status->value => [
				'options' => ['default' => true, 'type' => 'list', 'partial' => true],
			],
			PasswordlessRequestField::DateSent->value => [
				'options' => [
					'default' => true,
					'type' => 'date',
					'exclude' => [
						DateType::TOMORROW,
						DateType::NEXT_DAYS,
						DateType::NEXT_WEEK,
						DateType::NEXT_MONTH,
					],
				],
			],
		];

		foreach ($fieldsList as $column => $field)
		{
			$result[$column] = $this->createField(
				$column,
				$field['options'] ?? [],
			);
		}

		return $result;
	}
}
