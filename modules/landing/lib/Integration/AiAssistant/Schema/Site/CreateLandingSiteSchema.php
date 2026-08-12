<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class CreateLandingSiteSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'siteTitle' => [
				'type' => 'string',
				'description' => 'Title of the site to create. Must be a non-empty string.',
				'minLength' => 1,
			],
			'pageTitle' => [
				'type' => ['string', 'null'],
				'description' => 'Title of the initial page. Defaults to the site title when omitted.',
			],
			'siteCode' => [
				'type' => ['string', 'null'],
				'description' => 'Optional site code used in the URL. If omitted, Landing generates one from the title.',
			],
			'pageCode' => [
				'type' => ['string', 'null'],
				'description' => 'Optional code of the initial page. If omitted, Landing generates one automatically.',
			],
			'description' => [
				'type' => ['string', 'null'],
				'description' => 'Optional description saved to the site and the initial page metadata.',
			],
			'publish' => [
				'type' => ['boolean', 'null'],
				'description' => 'Set to true to publish the site right after creation. Defaults to false.',
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['siteTitle'];
	}
}
