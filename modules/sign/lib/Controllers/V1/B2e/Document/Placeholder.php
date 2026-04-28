<?php

namespace Bitrix\Sign\Controllers\V1\B2e\Document;

use Bitrix\Main;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Attribute\ActionAccess;
use Bitrix\Sign\Engine\Controller;
use Bitrix\Sign\Repository\Grid\PlaceholderRepository;
use Bitrix\Sign\Service\Document\Placeholder\PlaceholderCollectorService;
use Bitrix\Sign\Attribute;

class Placeholder extends Controller
{

	#[Attribute\Access\LogicOr(
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_TEMPLATE_EDIT),
	)]
	public function listAction(
		PlaceholderCollectorService $placeholderService,
		bool $clearCache = false,
	): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId < 1)
		{
			$this->addError(new Main\Error('Current user not found'));

			return [];
		}

		$result = $placeholderService->loadPlaceholdersByUserId($currentUserId, $clearCache);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return $result->getData();
	}

	#[Attribute\Access\LogicOr(
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_TEMPLATE_EDIT),
	)]
	public function listByHcmLinkIdAction(
		int $hcmLinkCompanyId,
		PlaceholderCollectorService $placeholderService,
	): array
	{
		$result = $placeholderService->loadPlaceholdersByHcmLinkCompanyId($hcmLinkCompanyId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return $result->getData();
	}

	#[Attribute\Access\LogicOr(
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_TEMPLATE_EDIT),
	)]
	public function saveLastSelectionBySelectorTypeAction(
		string $selectorType,
		int $value,
		PlaceholderRepository $placeholderRepository,
	): void
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId < 1)
		{
			$this->addError(new Main\Error('Current user not found'));

			return;
		}

		$placeholderRepository->saveLastSelectionBySelectorTypeAction($selectorType, $value, $currentUserId);
	}

	#[Attribute\Access\LogicOr(
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_TEMPLATE_EDIT),
	)]
	public function getLastSelectionBySelectorTypeAction(
		string $selectorType,
		PlaceholderRepository $placeholderRepository,
	): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId < 1)
		{
			$this->addError(new Main\Error('Current user not found'));

			return [];
		}

		$lastSelectionResult = $placeholderRepository->getLastSelectionBySelectorTypeAction($selectorType, $currentUserId);
		if (!$lastSelectionResult->isSuccess())
		{
			$this->addError(new Main\Error('Could not get last selection'));

			return [];
		}

		return $lastSelectionResult->getData();
	}
}
