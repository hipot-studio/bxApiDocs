<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Requisite;

use Bitrix\Landing\Manager;
use Bitrix\Landing\Site;
use Bitrix\Landing\Site\Type;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;
use Bitrix\Rest\AppTable;

/**
 * Some workers related to Requisite
 */
class Factory
{
	private const DEFAULT_CODE = 'LANDING';

	public static function contextualizeEvent(Event $event): Context
	{
		$code = $event->getParameter('CODE') ?? self::DEFAULT_CODE;

		$content = $event->getParameter('CONTENT') ?? [];
		$context = (new Context())
			->setCode($code)
			->setData($content['~DATA'] ?? null)
			->setRatio(
				Dictionary\Ratio::fromArray($event->getParameter('RATIO')[$code] ?? [])
			)
			->setUserId((int)$event->getParameter('USER_ID'))
			->setAdditionalOptions(
				Dictionary\AdditionalOption::fromArray($event->getParameter('ADDITIONAL_OPTION') ?? [])
			)
			->setStructureByUserContext($event->getParameter('CONTEXT_USER'))
		;

		$siteId =
			$context->getRatio()?->get(Dictionary\RatioPart::SiteId)
				?? $context->getAdditionalOptions()?->get(Dictionary\AdditionalOptionPart::SiteId)
				?? $context->getAdditionalOptions()?->get(Dictionary\AdditionalOptionPart::ReplaceSiteId)
		;
		$siteId = (int)$siteId;
		$siteId =
			$siteId > 0
				? $siteId
				: (int)$context->getAdditionalOptions()?->get(Dictionary\AdditionalOptionPart::ReplaceSiteId);
		if ($siteId > 0)
		{
			$context->setSiteId($siteId);
		}

		$appCode =
			$context->getRatio()->get(RatioPart::AppCode)
			?? $context->getAdditionalOptions()?->get(AdditionalOptionPart::AppCode);
		$appId = $event->getParameter('APP_ID');
		if (
			!isset($appCode)
			&& isset($appId)
			&& Loader::includeModule('rest')
		)
		{
			$app = AppTable::getById($appId)->fetch();
			$appCode = $app['CODE'] ?? null;
		}
		if (isset($appCode))
		{
			$context->setAppCode($appCode);
		}

		return $context;
	}

	public static function returnalizeContext(Context $context): array
	{
		$ratio = $context->getRatio();

		if ((int)$context->getSiteId() >= 0)
		{
			$ratio->set(Dictionary\RatioPart::SiteId, (int)$context->getSiteId());
		}

		if ($context->getAppCode() !== null)
		{
			$ratio->set(Dictionary\RatioPart::AppCode, $context->getAppCode());
		}

		// todo: save app code and app id to ratio

		return [
			'RATIO' => $ratio->toArray(),
		];
	}

	public static function returnalizeFinishContext(Context $context): array
	{
		$siteId = $context->getSiteId();
		if (!isset($siteId))
		{
			return [];
		}

		$linkDto = new FinishRedirectLinkDto(
			href: '#' . $siteId,
			text: Loc::getMessage('LANDING_IMPORT_FINISH_GOTO_SITE'),
			className: 'ui-btn ui-btn-md ui-btn-success ui-btn-round',
			target: '_top',
			dataIsSite: 'Y',
			dataSiteId: $siteId,
		);

		$ratio = $context->getRatio();
		$additional = $context->getAdditionalOptions();
		$indexPageId = PageResolver::getIndexPageId($context);

		if ($indexPageId > 0)
		{
			$linkDto->dataIsLanding = 'Y';
			$linkDto->dataLandingId = $indexPageId;

			if (
				$additional->get(AdditionalOptionPart::SiteId)
				|| $additional->get(AdditionalOptionPart::ReplacePageId)
			)
			{
				$linkDto->text = Loc::getMessage('LANDING_IMPORT_FINISH_GOTO_PAGE');
			}
		}

		$siteType = $ratio->get(RatioPart::SiteType) ?? '';
		$scopeRedirect = Type::onTransferFinishRedirectUrlGet($siteId);
		if ($scopeRedirect)
		{
			$linkDto->applyOverride($scopeRedirect);
		}
		elseif (
			$siteType === Type::SCOPE_CODE_DEFAULT
			&& !$additional->isEmpty()
			&& ($editorUrl = self::getLandingViewUrl($siteId, $indexPageId))
		)
		{
			$linkDto->href = $editorUrl;
		}
		// manual import
		elseif ($siteType === Type::SCOPE_CODE_DEFAULT && $additional->isEmpty())
		{
			$url = Manager::getOption('tmp_last_show_url', '');
			if ($url !== '')
			{
				$linkDto->href = str_replace(
					[
						'#site_show#',
						'#landing_edit#',
					],
					[
						$siteId,
						// todo: it's correct?
						$siteId,
					],
					$url
				);
			}
			else
			{
				$linkDto->href = '/sites/';
			}
		}

		$replaceLid = (int)$additional->get(AdditionalOptionPart::ReplacePageId);
		if ($replaceLid > 0)
		{
			$linkDto->dataReplaceLid = $replaceLid;
		}

		$href = $linkDto->href;
		$finalLinkAttrs = [
			'class' => $linkDto->className,
			'data-is-site' => $linkDto->dataIsSite,
			'data-site-id' => $linkDto->dataSiteId,
			'href' => $href,
			'target' => $linkDto->target,
		];
		if ($linkDto->dataIsLanding !== null)
		{
			$finalLinkAttrs['data-is-landing'] = $linkDto->dataIsLanding;
		}
		if ($linkDto->dataLandingId !== null)
		{
			$finalLinkAttrs['data-landing-id'] = $linkDto->dataLandingId;
		}
		if ($linkDto->dataReplaceLid !== null)
		{
			$finalLinkAttrs['data-replace-lid'] = $linkDto->dataReplaceLid;
		}

		$domList = [
			[
				'TAG' => 'a',
				'DATA' => [
					'attrs' => $finalLinkAttrs,
					'text' => $linkDto->text,
				],
			],
		];

		if (!str_starts_with($href, '#'))
		{
			$script = "setTimeout(() => {
					top.window.location.href='{$href}';
				}, 5000);";
			$domList[] = [
				'TAG' => 'script',
				'DATA' => [
					'html' => $script,
				],
			];
		}

		return [
			'CREATE_DOM_LIST' => $domList,
			'ADDITIONAL' => [
				'id' => $siteId,
				'publicUrl' => Site::getPublicUrl($siteId),
				'imageUrl' => Manager::getUrlFromFile(Site::getPreview($siteId)),
			],
		];
	}

	public static function isIntroRun(Context $context): bool
	{
		return $context->getRatio()->get(RatioPart::SiteId) === null;
	}

	private static function getLandingViewUrl(int $siteId, int $landingId): ?string
	{
		if ($siteId <= 0 || $landingId <= 0)
		{
			return null;
		}

		if (!Manager::isB24() && !Loader::includeModule('intranet'))
		{
			return null;
		}

		$url = "/sites/site/{$siteId}/view/{$landingId}/";
		$uri = new Uri($url);
		$uri->addParams([
			'newLanding' => 'Y',
		]);

		return $uri->getUri();
	}
}
