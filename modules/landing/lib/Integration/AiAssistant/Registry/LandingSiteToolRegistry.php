<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Registry;

use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\CancelMarkupJobTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\ChangeAiSiteTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\CreateAiSiteTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\CreateLandingSiteTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\DeleteLandingSiteTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\GetCrmFormFieldsTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\GetLandingCrmFormBlocksTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\GetAiSiteChangeStatusTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\GetAiSiteHandoffLinkTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\GetAiSiteStatusTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\GetMarkupJobStatusTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\GetMarkupPackageTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\ListCrmFormAvailableFieldsTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\ListCrmFormsTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\PublishLandingSiteTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\PutMarkupFileTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\RestoreLandingSiteTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\SearchDeletedLandingSitesTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\SearchLandingSitesTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\SetLandingCrmFormTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\SubmitMarkupPackageTool;
use Bitrix\Landing\Integration\AiAssistant\Tool\Site\UpdateCrmFormFieldsTool;

final class LandingSiteToolRegistry
{
	private function __construct()
	{
	}

	/**
	 * @return list<class-string<BaseTool>>
	 */
	public static function getToolClasses(): array
	{
		return [
			...self::getBaseLandingToolClasses(),
			...self::getCrmFormToolClasses(),
			...self::getAiSiteToolClasses(),
			...self::getMarkupToolClasses(),
		];
	}

	/**
	 * @return list<class-string<BaseTool>>
	 */
	public static function getBaseLandingToolClasses(): array
	{
		return [
			CreateLandingSiteTool::class,
			SearchLandingSitesTool::class,
			SearchDeletedLandingSitesTool::class,
			DeleteLandingSiteTool::class,
			RestoreLandingSiteTool::class,
			PublishLandingSiteTool::class,
		];
	}

	/**
	 * @return list<class-string<BaseTool>>
	 */
	public static function getCrmFormToolClasses(): array
	{
		return [
			ListCrmFormsTool::class,
			GetLandingCrmFormBlocksTool::class,
			SetLandingCrmFormTool::class,
			GetCrmFormFieldsTool::class,
			ListCrmFormAvailableFieldsTool::class,
			UpdateCrmFormFieldsTool::class,
		];
	}

	/**
	 * @return list<class-string<BaseTool>>
	 */
	public static function getAiSiteToolClasses(): array
	{
		return [
			CreateAiSiteTool::class,
			GetAiSiteStatusTool::class,
			ChangeAiSiteTool::class,
			GetAiSiteChangeStatusTool::class,
			GetAiSiteHandoffLinkTool::class,
		];
	}

	/**
	 * @return list<class-string<BaseTool>>
	 */
	public static function getMarkupToolClasses(): array
	{
		return [
			PutMarkupFileTool::class,
			SubmitMarkupPackageTool::class,
			GetMarkupJobStatusTool::class,
			GetMarkupPackageTool::class,
			CancelMarkupJobTool::class,
		];
	}

	/**
	 * @return list<class-string<BaseTool>>
	 */
	public static function getLifecycleToolClasses(): array
	{
		return self::getToolClasses();
	}
}
