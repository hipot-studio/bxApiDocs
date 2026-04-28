<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm;

use Bitrix\Main\Config\Option;
use CCrmOwnerType;

class ClientDataProvider
{
	public function getContactsByIds(array $contactIds): array
	{
		if (empty($contactIds))
		{
			return [];
		}

		$contactIds = array_keys($contactIds);

		$result = \CCrmContact::GetListEx(
			[],
			[
				'@ID' => $contactIds,
				//@todo review later
				'CHECK_PERMISSIONS' => 'N',
			],
			false,
			false,
			[
				'ID',
				'HONORIFIC',
				'NAME',
				'SECOND_NAME',
				'LAST_NAME',
				'CATEGORY_ID',
				'PHOTO',
			],
		);

		$contactUrlTemplate = Option::get('crm', 'path_to_contact_details', '/crm/contact/details/#contact_id#/');

		$contacts = [];
		while ($row = $result->fetch())
		{
			$id = (int)$row['ID'];

			$contacts[$id] = [
				'id' => $id,
				'nameOnly' => $row['NAME'] ?? '',
				'name' => \CCrmContact::PrepareFormattedName($row),
				'image' => self::getImageSrc((int)($row['PHOTO'] ?? 0)),
				'url' => \CComponentEngine::MakePathFromTemplate($contactUrlTemplate, ['contact_id' => $id]),
				'phones' => [],
				'emails' => [],
			];
		}

		$this->fillCommunications($contacts, CCrmOwnerType::ContactName, $contactIds);

		return $contacts;
	}

	public function getCompaniesByIds(array $companyIds): array
	{
		if (empty($companyIds))
		{
			return [];
		}

		$companyIds = array_keys($companyIds);

		$result = \CCrmCompany::GetListEx(
			[],
			[
				'@ID' => $companyIds,
				//@todo review later
				'CHECK_PERMISSIONS' => 'N',
			],
			false,
			false,
			[
				'ID',
				'TITLE',
				'CATEGORY_ID',
				'LOGO',
			]
		);

		$companyUrlTemplate = Option::get('crm', 'path_to_company_details', '/crm/company/details/#company_id#/');

		$companies = [];
		while ($row = $result->fetch())
		{
			$id = (int)$row['ID'];

			$companies[$id] = [
				'id' => $id,
				'nameOnly' => $row['TITLE'] ?? '',
				'name' => $row['TITLE'] ?? '',
				'image' => self::getImageSrc((int)($row['LOGO'] ?? 0)),
				'url' => \CComponentEngine::MakePathFromTemplate($companyUrlTemplate, ['company_id' => $id]),
				'phones' => [],
				'emails' => [],
			];
		}

		$this->fillCommunications($companies, CCrmOwnerType::CompanyName, $companyIds);

		return $companies;
	}

	private function fillCommunications(array &$entities, string $entityTypeName, array $elementIds): void
	{
		$communicationsResult = \CCrmFieldMulti::GetListEx(
			[],
			[
				'=ENTITY_ID' => $entityTypeName,
				'@ELEMENT_ID' => $elementIds,
				'@TYPE_ID' => ['PHONE', 'EMAIL'],
			],
		);
		while ($communication = $communicationsResult->fetch())
		{
			if (empty($entities[$communication['ELEMENT_ID']]))
			{
				continue;
			}

			if ($communication['TYPE_ID'] === 'PHONE')
			{
				$entities[$communication['ELEMENT_ID']]['phones'][] = $communication['VALUE'];
			}
			if ($communication['TYPE_ID'] === 'EMAIL')
			{
				$entities[$communication['ELEMENT_ID']]['emails'][] = $communication['VALUE'];
			}
		}
	}

	private function getImageSrc(int $imageId): string
	{
		$tmpData = \CFile::resizeImageGet(
			$imageId,
			[
				'width' => 100,
				'height' => 100,
			],
			BX_RESIZE_IMAGE_EXACT,
			false,
			false,
			true
		);

		return (!empty($tmpData['src']) ? $tmpData['src'] : '');
	}
}
