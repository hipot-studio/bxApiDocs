<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Main\ORM;
use Bitrix\Main\ArgumentException;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Transfer\Requisite;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\Script\Action\Closet\MakingStatus;
use Bitrix\Landing\Transfer\Script\Action\Closet\IAction;

abstract class Blank implements IAction
{
	private MakingStatus $makingStatus = MakingStatus::Filming;
	protected Requisite\Context $context;

	public function __construct(Requisite\Context $context)
	{
		$this->context = $context;
	}

	/**
	 * @inheritdoc
	 */
	abstract public function action(): void;

	protected function setEndEpisode(): void
	{
		$this->makingStatus = MakingStatus::EndEpisode;
	}

	public function isEndEpisode(): bool
	{
		return $this->makingStatus === MakingStatus::EndEpisode;
	}

	// /**
	//  * @param array $params - ORM query params
	//  * @return ORM\Query\Result
	//  */
	// protected function getSiteList(array $params): ORM\Query\Result
	// {
	// 	return Site::getList($params);
	// }

	// todo: need method?
	/**
	 * @param array $params - ORM query params
	 * @return ORM\Query\Result
	 */
	protected function getLandingList(array $params): ORM\Query\Result
	{
		return Landing::getList($params);
	}

	/**
	 * Check is current script work with full site import
	 * @return bool
	 */
	protected function isImportSiteScript(): bool
	{
		return
			!$this->isReplaceScript()
			&& !$this->isImportPageScript()
		;
	}

	/**
	 * Check is current script work with page (add or replace), not full site
	 * @return bool
	 */
	protected function isImportPageScript(): bool
	{
		return $this->context->getAdditionalOptions()->get(AdditionalOptionPart::SiteId) !== null;
	}

	/**
	 * Check is current script or replace type
	 * @return bool
	 * @throws ArgumentException
	 */
	protected function isReplaceScript(): bool
	{
		return $this->isReplaceSiteScript() || $this->isReplacePageScript();
	}

	/**
	 * Check is current script or replace site type
	 * @return bool
	 * @throws ArgumentException
	 */
	protected function isReplaceSiteScript(): bool
	{
		return $this->context->getAdditionalOptions()->get(AdditionalOptionPart::ReplaceSiteId) !== null;
	}

	/**
	 * Check is current script or replace page type
	 * @return bool
	 * @throws ArgumentException
	 */
	protected function isReplacePageScript(): bool
	{
		return $this->context->getAdditionalOptions()->get(AdditionalOptionPart::ReplacePageId) !== null;
	}
}
