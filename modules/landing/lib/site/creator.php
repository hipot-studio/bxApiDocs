<?php
namespace Bitrix\Landing\Site;

use Bitrix\Landing\Domain;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Site as SiteEntity;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;

class Creator
{
	private const EMPTY_SITE_TEMPLATE_CODE = 'empty';
	private const EMPTY_MULTIPAGE_SITE_TEMPLATE_CODE = 'empty-multipage';

	/**
	 * Creates a new empty site with one empty page and returns editor redirect URL.
	 *
	 * @param string $siteType Site type.
	 * @param string $siteTitle Created site title.
	 * @param string $pageTitle Created page title.
	 * @return Result
	 */
	public static function createEmptySiteWithEmptyPage(string $siteType, string $siteTitle, string $pageTitle): Result
	{
		$siteType = mb_strtoupper(trim($siteType));
		if ($siteType === '')
		{
			$siteType = SiteEntity::getDefaultType();
		}

		$templateResult = null;
		if (self::shouldCreateByTemplate($siteType))
		{
			$templateResult = self::createEmptySiteWithEmptyPageByTemplate($siteType, $siteTitle, $pageTitle);
			if ($templateResult->isSuccess())
			{
				return $templateResult;
			}
		}

		$siteResult = null;
		$landingResult = null;
		$siteId = 0;

		if (Rights::isOn() && !self::canCreateSite())
		{
			$result = new Result();
			$result->addError(new Error('Access denied.', 'ACCESS_DENIED'));

			return $result;
		}

		$rightsState = Rights::isOn();
		Rights::setOff();
		try
		{
			$siteFields = [
				'TITLE' => $siteTitle,
				'TYPE' => $siteType,
				'ACTIVE' => 'N',
				'CODE' => mb_strtolower(Random::getString(8)),
			];

			$domainId = Type::getDomainId();
			if ($domainId === '' && !Manager::isB24())
			{
				$domainId = Domain::getCurrentId();
			}
			if ($domainId !== '')
			{
				$siteFields['DOMAIN_ID'] = $domainId;
			}

			$siteResult = SiteEntity::add($siteFields);
			if ($siteResult->isSuccess())
			{
				$siteId = (int)$siteResult->getId();
				$landingResult = Landing::add([
					'SITE_ID' => $siteId,
					'TITLE' => $pageTitle,
					'ACTIVE' => 'N',
					'PUBLIC' => 'N',
				]);
			}
		}
		finally
		{
			if ($rightsState)
			{
				Rights::setOn();
			}
			else
			{
				Rights::setOff();
			}
		}

		if (
			$siteResult instanceof Result
			&& $landingResult instanceof Result
			&& $siteResult->isSuccess()
			&& $landingResult->isSuccess()
		)
		{
			$landingId = (int)$landingResult->getId();

			$result = new Result();
			$result->setData([
				'siteId' => $siteId,
				'landingId' => $landingId,
				'redirectUrl' => self::buildLandingEditorUrl($siteId, $landingId),
			]);

			return $result;
		}

		if ($siteId > 0 && !($landingResult instanceof Result && $landingResult->isSuccess()))
		{
			SiteEntity::delete($siteId, true);
		}

		$directResult = new Result();
		if ($siteResult instanceof Result)
		{
			foreach ($siteResult->getErrors() as $error)
			{
				$directResult->addError($error);
			}
		}
		if ($landingResult instanceof Result)
		{
			foreach ($landingResult->getErrors() as $error)
			{
				$directResult->addError($error);
			}
		}

		$result = new Result();
		$directMessage = implode('; ', $directResult->getErrorMessages());
		$templateMessage = $templateResult instanceof Result ? implode('; ', $templateResult->getErrorMessages()) : '';
		$message = trim(
			($templateMessage !== '' ? 'template: ' . $templateMessage : '')
			. ($directMessage !== '' ? '; direct: ' . $directMessage : ''),
			'; '
		);
		$result->addError(new Error($message !== '' ? $message : 'Failed to create an empty site.'));

		return $result;
	}

	private static function createEmptySiteWithEmptyPageByTemplate(
		string $siteType,
		string $siteTitle,
		string $pageTitle
	): Result
	{
		$maxSiteIdBeforeCreate = 0;
		$beforeRow = SiteEntity::getList([
			'select' => ['ID'],
			'filter' => ['=TYPE' => $siteType],
			'order' => ['ID' => 'desc'],
			'limit' => 1,
		])->fetch();
		if ($beforeRow)
		{
			$maxSiteIdBeforeCreate = (int)$beforeRow['ID'];
		}

		$siteResult = SiteEntity::addByTemplate(self::getTemplateCode($siteType), $siteType);
		if (!$siteResult->isSuccess())
		{
			$result = new Result();
			foreach ($siteResult->getErrors() as $error)
			{
				$result->addError($error);
			}

			return $result;
		}

		$siteId = (int)$siteResult->getId();
		if ($siteId <= 0)
		{
			$createdSiteRow = SiteEntity::getList([
				'select' => ['ID'],
				'filter' => [
					'=TYPE' => $siteType,
					'>ID' => $maxSiteIdBeforeCreate,
				],
				'order' => ['ID' => 'asc'],
				'limit' => 1,
			])->fetch();
			$siteId = (int)($createdSiteRow['ID'] ?? 0);
		}

		if ($siteId <= 0)
		{
			$result = new Result();
			$result->addError(new Error('Unable to detect created site ID.'));

			return $result;
		}

		SiteEntity::update($siteId, [
			'TITLE' => $siteTitle,
		]);

		$siteRow = SiteEntity::getList([
			'select' => ['LANDING_ID_INDEX'],
			'filter' => ['ID' => $siteId],
		])->fetch();

		$landingId = (int)($siteRow['LANDING_ID_INDEX'] ?? 0);
		if ($landingId <= 0)
		{
			$landingRow = Landing::getList([
				'select' => ['ID'],
				'filter' => ['SITE_ID' => $siteId],
				'order' => ['ID' => 'asc'],
				'limit' => 1,
			])->fetch();
			$landingId = (int)($landingRow['ID'] ?? 0);
		}

		if ($landingId <= 0)
		{
			$result = new Result();
			$result->addError(new Error('Unable to detect created landing ID.'));

			return $result;
		}

		Landing::update($landingId, [
			'TITLE' => $pageTitle,
		]);

		$result = new Result();
		$result->setData([
			'siteId' => $siteId,
			'landingId' => $landingId,
			'redirectUrl' => self::buildLandingEditorUrl($siteId, $landingId),
		]);

		return $result;
	}

	private static function buildLandingEditorUrl(int $siteId, int $landingId): string
	{
		return '/sites/site/' . $siteId . '/view/' . $landingId . '/';
	}

	private static function getTemplateCode(string $siteType): string
	{
		return in_array($siteType, [Type::SCOPE_CODE_KNOWLEDGE, Type::SCOPE_CODE_GROUP], true)
			? self::EMPTY_MULTIPAGE_SITE_TEMPLATE_CODE
			: self::EMPTY_SITE_TEMPLATE_CODE
		;
	}

	private static function shouldCreateByTemplate(string $siteType): bool
	{
		return in_array($siteType, [Type::SCOPE_CODE_KNOWLEDGE, Type::SCOPE_CODE_GROUP], true);
	}

	private static function canCreateSite(): bool
	{
		$rights = Rights::getOperationsForSite(0);

		return Rights::hasAdditionalRight(Rights::ADDITIONAL_RIGHTS['create'])
			&& in_array(Rights::ACCESS_TYPES['edit'], $rights, true)
		;
	}
}
