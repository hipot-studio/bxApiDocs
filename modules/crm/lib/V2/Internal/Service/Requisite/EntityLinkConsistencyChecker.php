<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\EntityBankDetail;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Main;
use CCrmCompany;
use CCrmOwnerType;

/**
 * Whether a set of four identifiers may be written as the link row of an entity.
 *
 * The rule is one and the same for the client half and the seller half: a requisite has to belong to
 * the party the entity names, and a bank detail has to belong to the requisite chosen next to it. The
 * client half asks the entity who its client is; the seller half asks who its "my company" is, and
 * additionally that this company is really marked as one, {@see self::isMyCompany()}.
 *
 * Refusals are thrown, not returned, and every one of them carries a code of its own so that a caller
 * may tell them apart. The order of the checks is a part of the contract: identifiers are validated
 * before the client is read, the client half before the seller half, and within a half the requisite
 * before its bank detail. A caller that already knows the client and the seller may pass them in
 * `CLIENT_SELLER_INFO` and turn the reading of the entity off with `ENTITY_CHECK`.
 *
 * The identifiers are untyped on purpose: the check rejects anything that is not an `int`, and typed
 * parameters would coerce a numeric string at the boundary instead, which is exactly the case the
 * codes 202 and 204-207 exist for.
 *
 * @internal
 */
final class EntityLinkConsistencyChecker
{
	public const ERR_INVALID_ENTITY_TYPE = 201;
	public const ERR_INVALID_ENTITY_ID = 202;
	public const ERR_INVALID_REQUISITE_ID = 204;
	public const ERR_INVALID_BANK_DETAIL_ID = 205;
	public const ERR_INVALID_MC_REQUISITE_ID = 206;
	public const ERR_INVALID_MC_BANK_DETAIL_ID = 207;
	public const ERR_REQUISITE_NOT_FOUND = 208;
	public const ERR_REQUISITE_TIED_TO_ENTITY_WITHOUT_CLIENT = 209;
	public const ERR_REQUISITE_NOT_ASSIGNED = 210;
	public const ERR_BANK_DETAIL_NOT_FOUND = 211;
	public const ERR_BANK_DETAIL_NOT_ASSIGNED_WO_REQUISITE = 212;
	public const ERR_BANK_DETAIL_NOT_ASSIGNED = 213;
	public const ERR_MC_REQUISITE_TIED_TO_ENTITY_WITHOUT_MYCOMPANY = 214;
	public const ERR_MC_REQUISITE_NOT_FOUND = 215;
	public const ERR_MC_REQUISITE_NOT_ASSIGNED = 216;
	public const ERR_MC_BANK_DETAIL_NOT_ASSIGNED_WO_MC_REQUISITE = 217;
	public const ERR_MC_BANK_DETAIL_NOT_FOUND = 218;
	public const ERR_MC_BANK_DETAIL_NOT_ASSIGNED = 219;
	public const ERR_ENTITY_CHECK_N_BUT_CLIENT_NOT_SPECIFIED = 220;

	private readonly EntityLinkService $linkService;

	private readonly EntityClientSellerResolver $clientSellerResolver;

	public function __construct(
		?EntityLinkService $linkService = null,
		?EntityClientSellerResolver $clientSellerResolver = null
	)
	{
		$this->linkService = $linkService ?? EntityLinkService::getInstance();
		$this->clientSellerResolver = $clientSellerResolver ?? new EntityClientSellerResolver();
	}

	/**
	 * @param mixed $options `ENTITY_CHECK` turns the reading of the entity off, `CLIENT_SELLER_INFO`
	 *                       supplies the client and the seller instead of reading them, and
	 *                       `IS_MY_COMPANY_CHECK` set to anything but `Y` trusts the seller as given
	 * @throws Main\SystemException on the first violation found
	 */
	public function check(
		mixed $entityTypeId,
		mixed $entityId,
		mixed $requisiteId,
		mixed $bankDetailId,
		mixed $mcRequisiteId,
		mixed $mcBankDetailId,
		mixed $options = null
	): void
	{
		$enableEntityCheck = (!is_array($options) || !isset($options['ENTITY_CHECK'])
			|| $options['ENTITY_CHECK'] === 'Y' || $options['ENTITY_CHECK'] === true);
		$skipIsMyCompanyCheck = is_array($options) && isset($options['IS_MY_COMPANY_CHECK'])
			&& $options['IS_MY_COMPANY_CHECK'] !== 'Y' && $options['IS_MY_COMPANY_CHECK'] !== true;

		if (!$enableEntityCheck
			&& (!is_array($options)
				|| !is_array($options['CLIENT_SELLER_INFO'])
				|| !isset($options['CLIENT_SELLER_INFO']['CLIENT_ENTITY_TYPE_ID'])
				|| !isset($options['CLIENT_SELLER_INFO']['CLIENT_ENTITY_ID'])
				|| !(CCrmOwnerType::IsDefined($options['CLIENT_SELLER_INFO']['CLIENT_ENTITY_TYPE_ID'])
					|| $options['CLIENT_SELLER_INFO']['CLIENT_ENTITY_TYPE_ID'] === CCrmOwnerType::Undefined)
				|| ($options['CLIENT_ENTITY_ID'] ?? null) < 0))
		{
			throw new Main\SystemException(
				'The entity check is disabled, but client type and id are not specified.',
				self::ERR_ENTITY_CHECK_N_BUT_CLIENT_NOT_SPECIFIED
			);
		}

		$availableEntityTypeIds = $this->linkService->getAvailableEntityTypeIds();

		if ($enableEntityCheck && !isset($availableEntityTypeIds[$entityTypeId]))
		{
			throw new Main\SystemException(
				'Entity type is not defined or invalid.',
				self::ERR_INVALID_ENTITY_TYPE
			);
		}

		if ($enableEntityCheck && !(is_int($entityId) && $entityId > 0))
		{
			throw new Main\SystemException(
				'Entity identifier is not defined or invalid.',
				self::ERR_INVALID_ENTITY_ID
			);
		}

		if (!is_int($requisiteId) || $requisiteId < 0)
		{
			throw new Main\SystemException(
				'Requisite identifier is not defined or invalid.',
				self::ERR_INVALID_REQUISITE_ID
			);
		}

		if (!is_int($bankDetailId) || $bankDetailId < 0)
		{
			throw new Main\SystemException(
				'BankDetail identifier is not defined or invalid.',
				self::ERR_INVALID_BANK_DETAIL_ID
			);
		}

		if (!is_int($mcRequisiteId) || $mcRequisiteId < 0)
		{
			throw new Main\SystemException(
				'Requisite identifier of your company is not defined or invalid.',
				self::ERR_INVALID_MC_REQUISITE_ID
			);
		}

		if (!is_int($mcBankDetailId) || $mcBankDetailId < 0)
		{
			throw new Main\SystemException(
				'BankDetail identifier of your company is not defined or invalid.',
				self::ERR_INVALID_MC_BANK_DETAIL_ID
			);
		}

		if (is_array($options) && is_array($options['CLIENT_SELLER_INFO']))
		{
			$clientSellerInfo = $options['CLIENT_SELLER_INFO'];
		}
		else
		{
			$clientSellerInfo = $this->clientSellerResolver->getClientSellerInfo($entityTypeId, $entityId);
		}

		$requisite = new EntityRequisite();
		$bankDetail = new EntityBankDetail();
		$entityTypeName = null;

		if ($requisiteId > 0)
		{
			if ($clientSellerInfo['CLIENT_ENTITY_TYPE_ID'] === CCrmOwnerType::Undefined
				|| $clientSellerInfo['CLIENT_ENTITY_ID'] <= 0)
			{
				if ($entityTypeName === null)
				{
					$entityTypeName = ucfirst(mb_strtolower(CCrmOwnerType::ResolveName($entityTypeId)));
				}
				throw new Main\SystemException(
					"Requisite with ID '$requisiteId' can not be tied to the $entityTypeName "
						. "in which the client is not selected.",
					self::ERR_REQUISITE_TIED_TO_ENTITY_WITHOUT_CLIENT
				);
			}

			$res = $requisite->getList([
				'filter' => ['=ID' => $requisiteId],
				'select' => ['ID', 'ENTITY_TYPE_ID', 'ENTITY_ID'],
			]);
			$row = $res->fetch();
			unset($res);
			if (!is_array($row))
			{
				throw new Main\SystemException(
					"The Requisite with ID '$requisiteId' is not found.",
					self::ERR_REQUISITE_NOT_FOUND
				);
			}
			$rqEntityTypeId = isset($row['ENTITY_TYPE_ID']) ? (int)$row['ENTITY_TYPE_ID'] : 0;
			$rqEntityId = isset($row['ENTITY_ID']) ? (int)$row['ENTITY_ID'] : 0;
			$clientEntityTypeId = (int)$clientSellerInfo['CLIENT_ENTITY_TYPE_ID'];
			$clientEntityId = (int)$clientSellerInfo['CLIENT_ENTITY_ID'];
			if ($clientEntityTypeId !== $rqEntityTypeId || $clientEntityId !== $rqEntityId)
			{
				$clientEntityTypeName = ucfirst(mb_strtolower(CCrmOwnerType::ResolveName($clientEntityTypeId)));
				throw new Main\SystemException(
					"The Requisite with ID '$requisiteId' is not assigned to $clientEntityTypeName "
						. "with ID '$clientEntityId'.",
					self::ERR_REQUISITE_NOT_ASSIGNED
				);
			}
		}

		if ($bankDetailId > 0)
		{
			if ($requisiteId <= 0)
			{
				throw new Main\SystemException(
					"The BankDetail can not be assigned without Requisite.",
					self::ERR_BANK_DETAIL_NOT_ASSIGNED_WO_REQUISITE
				);
			}

			$res = $bankDetail->getList([
				'filter' => ['=ID' => $bankDetailId],
				'select' => ['ID', 'ENTITY_TYPE_ID', 'ENTITY_ID'],
			]);
			$row = $res->fetch();
			unset($res);
			if (!is_array($row))
			{
				throw new Main\SystemException(
					"The BankDetail with ID '$bankDetailId' is not found.",
					self::ERR_BANK_DETAIL_NOT_FOUND
				);
			}
			$bdEntityTypeId = isset($row['ENTITY_TYPE_ID']) ? (int)$row['ENTITY_TYPE_ID'] : 0;
			$bdEntityId = isset($row['ENTITY_ID']) ? (int)$row['ENTITY_ID'] : 0;
			if ($bdEntityTypeId !== CCrmOwnerType::Requisite || $bdEntityId !== $requisiteId)
			{
				throw new Main\SystemException(
					"The BankDetail with ID '$bankDetailId' is not assigned to Requisite with ID '$requisiteId'.",
					self::ERR_BANK_DETAIL_NOT_ASSIGNED
				);
			}
		}

		if ($mcRequisiteId > 0)
		{
			if ($clientSellerInfo['MYCOMPANY_ID'] <= 0
				|| (!$skipIsMyCompanyCheck && $clientSellerInfo['MYCOMPANY_ID'] > 0
					&& !$this->isMyCompany((int)$clientSellerInfo['MYCOMPANY_ID'])))
			{
				if ($entityTypeName === null)
				{
					$entityTypeName = ucfirst(mb_strtolower(CCrmOwnerType::ResolveName($entityTypeId)));
				}
				throw new Main\SystemException(
					"Requisite of your company with ID '$requisiteId' can not be tied to the $entityTypeName "
					. "in which your company is not selected.",
					self::ERR_MC_REQUISITE_TIED_TO_ENTITY_WITHOUT_MYCOMPANY
				);
			}

			$myCompanyId = (int)$clientSellerInfo['MYCOMPANY_ID'];
			$res = $requisite->getList([
				'filter' => ['=ID' => $mcRequisiteId],
				'select' => ['ID', 'ENTITY_TYPE_ID', 'ENTITY_ID'],
			]);
			$row = $res->fetch();
			unset($res);
			if (!is_array($row))
			{
				throw new Main\SystemException(
					"The Requisite of your company with ID '$mcRequisiteId' is not found.",
					self::ERR_MC_REQUISITE_NOT_FOUND
				);
			}
			$rqEntityTypeId = isset($row['ENTITY_TYPE_ID']) ? (int)$row['ENTITY_TYPE_ID'] : 0;
			$rqEntityId = isset($row['ENTITY_ID']) ? (int)$row['ENTITY_ID'] : 0;
			if ($rqEntityTypeId !== CCrmOwnerType::Company || $rqEntityId !== $myCompanyId)
			{
				throw new Main\SystemException(
					"The Requisite with ID '$mcRequisiteId' is not assigned to your company with ID '$myCompanyId'.",
					self::ERR_MC_REQUISITE_NOT_ASSIGNED
				);
			}
		}

		if ($mcBankDetailId > 0)
		{
			if ($mcRequisiteId <= 0)
			{
				throw new Main\SystemException(
					"The BankDetail of your company can not be assigned without Requisite of your company.",
					self::ERR_MC_BANK_DETAIL_NOT_ASSIGNED_WO_MC_REQUISITE
				);
			}

			$res = $bankDetail->getList([
				'filter' => ['=ID' => $mcBankDetailId],
				'select' => ['ID', 'ENTITY_TYPE_ID', 'ENTITY_ID'],
			]);
			$row = $res->fetch();
			unset($res);
			if (!is_array($row))
			{
				throw new Main\SystemException(
					"The BankDetail of your company with ID '$mcBankDetailId' is not found.",
					self::ERR_MC_BANK_DETAIL_NOT_FOUND
				);
			}
			$bdEntityTypeId = isset($row['ENTITY_TYPE_ID']) ? (int)$row['ENTITY_TYPE_ID'] : 0;
			$bdEntityId = isset($row['ENTITY_ID']) ? (int)$row['ENTITY_ID'] : 0;
			if ($bdEntityTypeId !== CCrmOwnerType::Requisite || $bdEntityId !== $mcRequisiteId)
			{
				throw new Main\SystemException(
					"The BankDetail of your company with ID '$mcBankDetailId' is not assigned to "
						. "Requisite of your company with ID '$mcRequisiteId'.",
					self::ERR_MC_BANK_DETAIL_NOT_ASSIGNED
				);
			}
		}
	}

	/**
	 * Whether the company is one of the seller companies of the portal. Read past the permission
	 * check: the answer is a property of the portal, not of what the user may see.
	 */
	public function isMyCompany(int $companyId): bool
	{
		if ($companyId <= 0)
		{
			return false;
		}

		$res = CCrmCompany::GetListEx(
			[],
			[
				'=ID' => $companyId,
				'=IS_MY_COMPANY' => 'Y',
				'CHECK_PERMISSIONS' => 'N',
			],
			false,
			false,
			['ID']
		);

		return is_object($res) && (bool)$res->Fetch();
	}
}
