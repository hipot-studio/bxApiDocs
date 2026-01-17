<?php

namespace Bitrix\Intranet\Infrastructure\Controller;

use Bitrix\Intranet\ActionFilter\UserType;
use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Internal\Integration\Main\AnnualSummarySign;
use Bitrix\Intranet\Internal\Provider\AnnualSummary\FeatureProvider;
use Bitrix\Intranet\Internal\Service\AnnualSummary\Visibility;
use Bitrix\Intranet\Public\Provider\Portal\DomainProvider;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Routing\Exceptions\ParameterNotFoundException;

class AnnualSummary extends Controller
{
	protected function getDefaultPreFilters()
	{
		return [
			...parent::getDefaultPreFilters(),
			new UserType(['employee' , 'extranet']),
		];
	}

	/**
	 * @throws ParameterNotFoundException
	 */
	public function getLinkAction(string $signedUserId, string $signedType): array
	{
		$path = "/pub/annual_summary/{$signedUserId}/{$signedType}/";
		$path .= '?user_lang=' . LANGUAGE_ID;
		$shortUri = \CBXShortUri::GetShortUri($path);
		$shortUri = trim($shortUri, '/');
		$shortPath = "/pub/annuals/{$shortUri}/";
		$uri = (new DomainProvider())->getUri()->setPath($shortPath);

		return ['link' => $uri->getLocator()];
	}

	/**
	 * @ajaxAction intranet.v2.AnnualSummary.load
	 */
	public function loadAction(): array
	{
		return (new FeatureProvider())->getTop();
	}

	/**
	 * @ajaxAction intranet.v2.AnnualSummary.feature
	 * @throws \Exception
	 */
	public function featureAction(string $signedUserId, string $signedType): array
	{
		$signer = new AnnualSummarySign();
		$userId = $signer->unsign($signedUserId);
		$type = $signer->unsign($signedType);

		return (new FeatureProvider())->getShared($userId, $type);
	}

	/**
	 * @ajaxAction intranet.v2.AnnualSummary.canFirstShow
	 * @throws \Exception
	 */
	public function canFirstShowAction(): bool
	{
		if (!CurrentUser::get()->getId())
		{
			return false;
		}

		return (new Visibility(CurrentUser::get()->getId()))->canForceShow();
	}

	/**
	 * @ajaxAction intranet.v2.AnnualSummary.canShow
	 */
	public function canShowAction(): bool
	{
		if (!CurrentUser::get()->getId())
		{
			return false;
		}

		return (new Visibility(CurrentUser::get()->getId()))->canShow();
	}

	/**
	 * @ajaxAction intranet.v2.AnnualSummary.markShow
	 * @return bool
	 */
	public function markShowAction(): bool
	{
		return \CUserOptions::SetOption('intranet', 'annual_summary_25_last_show', time());
	}

	/**
	 * @ajaxAction intranet.v2.AnnualSummary.getFeatureByCode
	 * @return array
	 */
	public function getFeatureByCodeAction(string $code): array
	{
		$urlData = \CBXShortUri::GetUri($code);
		preg_match(
			"/^\/pub\/annual_summary\/(?'signedUserId'[^\/]+)\/(?'signedType'[^\/]+)\/?/",
				$urlData['URI'] ?? '',
			$matches,
		);

		return [
			'signedUserId' => $matches['signedUserId'] ?? '',
			'signedType' => $matches['signedType'] ?? '',
		];
	}
}
