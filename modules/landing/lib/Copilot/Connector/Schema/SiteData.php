<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema;

use Bitrix\Landing\Copilot\Connector\Schema\Dto\SchemaNodeDto;

class SiteData extends Schema
{
	/**
	 * Returns the name of the schema for site data.
	 *
	 * @return string The schema name.
	 */
	protected function getName(): string
	{
		return 'siteDataSchema';
	}

	/**
	 * Creates the full structure schema for siteData including nested titles, images, fonts, and colors objects.
	 *
	 * @return SchemaNodeDto Fully built siteData structure schema.
	 */
	protected function getStructure(): SchemaNodeDto
	{
		$propertiesSiteData = [
			'isAllowedRequest' => $this->isAllowedRequestSchema(),
			'titles' => $this->titlesSchema(),
			'description' => $this->descriptionSchema(),
			'keywords' => $this->keywordsSchema(),
			'images' => $this->imagesSchema(),
			'fonts' => $this->fontsSchema(),
			'colors' => $this->colorsSchema(),
		];

		$params = [
			'properties' => $propertiesSiteData
		];
		$objectSiteData = new SchemaNodeDto('object', 'siteData', $params);

		$properties = [
			$objectSiteData,
		];

		$params = [
			'properties' => $properties,
		];

		return new SchemaNodeDto('object', null, $params);
	}

	/**
	 * Creates the schema for the isAllowedRequest flag.
	 *
	 * @return SchemaNodeDto Fully built isAllowedRequest structure schema.
	 */
	private function isAllowedRequestSchema(): SchemaNodeDto
	{
		return new SchemaNodeDto('string');
	}

	/**
	 * Creates the structure schema for title strings for site and page.
	 *
	 * @return SchemaNodeDto Fully built titles object structure schema.
	 */
	private function titlesSchema(): SchemaNodeDto
	{
		$stringSite = new SchemaNodeDto('string', 'site');
		$stringPage = new SchemaNodeDto('string', 'page');

		$properties = [
			$stringSite,
			$stringPage,
		];

		$params = [
			'properties' => $properties,
		];

		return new SchemaNodeDto('object', null, $params);
	}

	/**
	 * Creates the structure schema for page description.
	 *
	 * @return SchemaNodeDto Fully built description object structure schema.
	 */
	private function descriptionSchema(): SchemaNodeDto
	{
		$string = new SchemaNodeDto('string', 'page');

		$properties = [
			$string
		];

		$params = [
			'properties' => $properties,
		];

		return new SchemaNodeDto('object', null, $params);
	}

	/**
	 * Creates the structure schema for page keywords.
	 *
	 * @return SchemaNodeDto Fully built keywords object structure schema.
	 */
	private function keywordsSchema(): SchemaNodeDto
	{
		$string = new SchemaNodeDto('string', 'page');

		$properties = [
			$string
		];

		$params = [
			'properties' => $properties,
		];

		return new SchemaNodeDto('object', null, $params);
	}

	/**
	 * Creates the structure schema for images including prompts and style hints.
	 *
	 * @return SchemaNodeDto Fully built images object structure schema.
	 */
	private function imagesSchema(): SchemaNodeDto
	{
		$stringTopic = new SchemaNodeDto('string', 'siteTopicEng');
		$stringStyle = new SchemaNodeDto('string', 'styleEng');
		$stringPrompt = new SchemaNodeDto('string', 'imgPromptEng');

		$properties = [
			$stringTopic,
			$stringStyle,
			$stringPrompt,
		];

		$params = [
			'properties' => $properties,
		];

		return new SchemaNodeDto('object', null, $params);
	}

	/**
	 * Creates the structure schema for fonts used for headers and texts.
	 *
	 * @return SchemaNodeDto Fully built fonts object structure schema.
	 */
	private function fontsSchema(): SchemaNodeDto
	{
		$string = new SchemaNodeDto('string', 'name');

		$fontProperties = [
			$string
		];

		$params = [
			'properties' => $fontProperties,
		];
		$fontHeaders = new SchemaNodeDto('object', 'headers', $params);
		$fontTexts = new SchemaNodeDto('object', 'texts', $params);

		$properties = [
			$fontHeaders,
			$fontTexts,
		];

		$params = [
			'properties' => $properties,
		];

		return new SchemaNodeDto('object', null, $params);
	}

	/**
	 * Creates the structure schema for multiple site color roles including theme, background, and contrast colors.
	 *
	 * @return SchemaNodeDto Fully built colors object structure schema.
	 */
	private function colorsSchema(): SchemaNodeDto
	{
		$stringHex = new SchemaNodeDto('string', 'hex');
		$stringName = new SchemaNodeDto('string', 'name');
		$colorProperties = [
			$stringHex,
			$stringName,
		];
		$params = [
			'properties' => $colorProperties,
		];
		$colorTheme = new SchemaNodeDto('object', 'theme', $params);
		$colorBg = new SchemaNodeDto('object', 'background', $params);
		$colorHeaderOnBg = new SchemaNodeDto('object', 'headerContrastOnBackground', $params);
		$colorTextOnBg = new SchemaNodeDto('object', 'textContrastOnBackground', $params);
		$colorHeaderOnTheme = new SchemaNodeDto('object', 'headerContrastOnTheme', $params);
		$colorTextOnTheme = new SchemaNodeDto('object', 'textContrastOnTheme', $params);

		$properties = [
			$colorTheme,
			$colorBg,
			$colorHeaderOnBg,
			$colorTextOnBg,
			$colorHeaderOnTheme,
			$colorTextOnTheme
		];

		$params = [
			'properties' => $properties,
		];

		return new SchemaNodeDto('object', null, $params);
	}
}