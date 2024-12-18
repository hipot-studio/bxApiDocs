<?php

namespace Bitrix\CrmMobile\Integration\Sale\Payment;

use Bitrix\Crm\Format\PersonNameFormatter;
use Bitrix\Crm\Service\Display;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sale\PaySystem\Manager;
use Bitrix\Crm\Service\Display\Field\UserField;
use Bitrix\Crm\Service\Display\Field;

LocHelper::loadMessages();

class EntityEditorFieldsProvider
{
	private ?DtoItemData $itemData = null;

	public function setItemData(?DtoItemData $itemData): EntityEditorFieldsProvider
	{
		$this->itemData = $itemData;

		return $this;
	}

	public function getSumField(array $props = []): array
	{
		return $this->mergeProps(
			[
				'name' => 'SUM',
				'title' => Loc::getMessage('M_CRM_TL_FIELD_NAME_SUM'),
				'type' => 'money',
				'config' => [
					'largeFont' => true,
				],
				'value' => $this->getSumFieldValue(),
			],
			$props
		);
	}

	private function getSumFieldValue(): ?array
	{
		if (is_null($this->itemData))
		{
			return null;
		}

		return [
			'amount' => $this->itemData->sum,
			'currency' => $this->itemData->currency,
		];
	}

	public function getClientField(array $props = []): array
	{
		return $this->mergeProps(
			[
				'name' => 'CLIENT',
				'title' => Loc::getMessage('M_CRM_TL_FIELD_NAME_CLIENT'),
				'type' => 'client_light',
				'value' => $this->getClientFieldValue(),
			],
			$props
		);
	}

	private function getClientFieldValue(): ?array
	{
		if (is_null($this->itemData))
		{
			return null;
		}

		$result = [
			'company' => [],
			'contact' => [],
		];

		$userPermissions = \CCrmPerms::GetCurrentUserPermissions();;

		if ($this->itemData->companyId > 0)
		{
			$isEntityReadPermitted = \CCrmCompany::CheckReadPermission($this->itemData->companyId, $userPermissions);
			$companyInfo = \CCrmEntitySelectorHelper::PrepareEntityInfo(
				\CCrmOwnerType::CompanyName,
				$this->itemData->companyId,
				[
					'ENTITY_EDITOR_FORMAT' => true,
					'IS_HIDDEN' => !$isEntityReadPermitted,
					'REQUIRE_REQUISITE_DATA' => true,
					'REQUIRE_MULTIFIELDS' => true,
					'NAME_TEMPLATE' => PersonNameFormatter::getFormat(),
				]
			);

			$result['company'] = [$companyInfo];
		}

		$iteration = 0;
		foreach ($this->itemData->contactIds as $contactId)
		{
			$isEntityReadPermitted = \CCrmContact::CheckReadPermission($contactId, $userPermissions);
			$result['contact'][] = \CCrmEntitySelectorHelper::PrepareEntityInfo(
				\CCrmOwnerType::ContactName,
				$contactId,
				[
					'ENTITY_EDITOR_FORMAT' => true,
					'IS_HIDDEN' => !$isEntityReadPermitted,
					'REQUIRE_REQUISITE_DATA' => true,
					'REQUIRE_EDIT_REQUISITE_DATA' => ($iteration === 0),
					'REQUIRE_MULTIFIELDS' => true,
					'REQUIRE_BINDINGS' => true,
					'NAME_TEMPLATE' => PersonNameFormatter::getFormat(),
					'NORMALIZE_MULTIFIELDS' => true,
				]
			);
			$iteration++;
		}

		return $result;
	}

	public function getClientName(array $props = []): array
	{
		return $this->mergeProps(
			[
				'name' => 'CLIENT_NAME',
				'title' => Loc::getMessage('M_CRM_TL_FIELD_NAME_CLIENT'),
				'type' => 'string',
			],
			$props
		);
	}

	public function getPhoneField(array $props = []): array
	{
		return $this->mergeProps(
			[
				'name' => 'PHONE',
				'title' => Loc::getMessage('M_CRM_TL_FIELD_NAME_PHONE'),
				'type' => 'phone',
				'value' => $this->getPhoneFieldValue(),
			],
			$props
		);
	}

	private function getPhoneFieldValue(): ?array
	{
		if (is_null($this->itemData))
		{
			return null;
		}

		return [
			'phoneNumber' => $this->itemData->phoneNumber,
		];
	}

	public function getStatusField(array $props = []): array
	{
		return $this->mergeProps(
			[
				'name' => 'STATUS',
				'title' => Loc::getMessage('M_CRM_TL_FIELD_NAME_STATUS'),
				'type' => 'status',
				'value' => $this->getStatusFieldValue(),
			],
			$props
		);
	}

	private function getStatusFieldValue(): ?array
	{
		if (is_null($this->itemData))
		{
			return null;
		}

		$status =
			$this->itemData->isPaid
				? [
				'id' => 'paid',
				'name' => mb_strtoupper(
					Loc::getMessage('M_CRM_TL_FIELD_NAME_STATUS_VALUE_PAID')
				),
			]
				: [
				'id' => 'unpaid',
				'name' => mb_strtoupper(
					Loc::getMessage('M_CRM_TL_FIELD_NAME_STATUS_VALUE_NOT_PAID')
				),
			];

		return [$status];
	}

	public function getDatePaidField(array $props = []): array
	{
		return $this->mergeProps(
			[
				'name' => 'DATE_PAID',
				'title' => Loc::getMessage('M_CRM_TL_FIELD_NAME_DATE_PAID'),
				'type' => 'datetime',
				'value' => $this->itemData?->datePaid,
			],
			$props
		);
	}

	public function getPaymentSystemField(array $props = []): array
	{
		return $this->mergeProps(
			[
				'name' => 'PAYMENT_SYSTEM',
				'title' => Loc::getMessage('M_CRM_TL_FIELD_NAME_PAYMENT_SYSTEM'),
				'type' => 'string',
				'value' =>
					(
						$this->itemData
						&& $this->itemData->paymentSystemId !== (int)Manager::getInnerPaySystemId()
					)
						? $this->itemData->paymentSystemName
						: null
				,
			],
			$props
		);
	}

	public function getSlipLinkField(array $props = []): array
	{
		return $this->mergeProps(
			[
				'name' => 'SLIP_LINK',
				'title' => Loc::getMessage('M_CRM_TL_FIELD_NAME_SLIP_LINK'),
				'type' => 'string',
				'value' => $this->itemData?->slipLink,
			],
			$props
		);
	}

	public function getResponsibleField(array $props = []): array
	{
		$userId = $this->getResponsibleId();

		$itemId = isset($this->itemData) ? $this->itemData->id : 0;
		$fieldName = 'RESPONSIBLE_ID';

		$displayItemValues =
			(new Display(\CCrmOwnerType::OrderPayment, [
				$fieldName => UserField::createByType(UserField::TYPE)
					->setContext(Field::MOBILE_CONTEXT),
			]))
				->setItems([
					$itemId => [
						$fieldName => $userId,
					]
				])
				->getValues($itemId)
		;

		$defaultProps = $this->mergeProps(
			[
				'name' => 'RESPONSIBLE_ID',
				'title' => Loc::getMessage('M_CRM_TL_FIELD_NAME_RESPONSIBLE'),
				'type' => 'user',
			],
			$displayItemValues[$fieldName] ?? []
		);

		return $this->mergeProps(
			$defaultProps,
			$props
		);
	}

	private function getResponsibleId(): ?int
	{
		if (is_null($this->itemData))
		{
			$currentUser = CurrentUser::get();

			return $currentUser?->getId();
		}

		return $this->itemData->responsibleId;
	}

	private function mergeProps(array $defaultProps, array $props): array
	{
		return array_merge($defaultProps, $props);
	}
}
