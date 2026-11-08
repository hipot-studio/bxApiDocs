<?php

namespace Bitrix\Socialnetwork\Component\BlogPostEdit;

use Bitrix\Blog\Item\Post;
use Bitrix\Socialnetwork\Util;

class Tag
{
	public static function getTagsFromPostData(array $params = []): array
	{
		$result = [];

		$blogId = (int)($params['blogId'] ?? 0);
		$inputTags = self::normalizeInputTags($params['tags'] ?? '');

		if (!empty($_POST['TAGS']))
		{
			$inputTags = self::normalizeInputTags($_POST['TAGS']);
		}

		if ($inputTags)
		{
			$blogCategoryList = [];

			$res = \CBlogCategory::getList([], [ 'BLOG_ID' => $blogId ]);
			while ($blogCategoryFields = $res->fetch())
			{
				$blogCategoryList[mb_strtolower($blogCategoryFields['NAME'])] = (int)$blogCategoryFields['ID'];
			}

			$tags = explode(',', $inputTags);
			$processedTagList = [];
			foreach ($tags as $tg)
			{
				$tg = trim($tg);
				$tagKey = mb_strtolower($tg);
				if (
					$tg === ''
					|| isset($processedTagList[$tagKey])
				)
				{
					continue;
				}
				$processedTagList[$tagKey] = true;

				$categoryId = (int)($blogCategoryList[$tagKey] ?? 0);
				if ($categoryId <= 0)
				{
					$categoryId = (int)\CBlogCategory::add([
						'BLOG_ID' => $blogId,
						'NAME' => $tg,
					]);
				}

				if ($categoryId > 0)
				{
					$blogCategoryList[$tagKey] = $categoryId;
					$result[] = $categoryId;
				}
			}
		}
		elseif (!empty($_POST['CATEGORY_ID']))
		{
			foreach ($_POST['CATEGORY_ID'] as $v)
			{
				$result[] = (int)(
				mb_strpos($v, 'new_') === 0
					? \CBlogCategory::add([
					'BLOG_ID' => $blogId,
					'NAME' => mb_substr($v, 4),
				])
					: $v
				);
			}
		}

		return $result;
	}

	/**
	 * A client is free to send TAGS as a list (TAGS[]=first&TAGS[]=second), so the value is reduced
	 * to the comma separated string the caller code expects.
	 */
	private static function normalizeInputTags(mixed $tags): string
	{
		if (!is_array($tags))
		{
			return is_scalar($tags) ? (string)$tags : '';
		}

		$scalarTags = array_filter($tags, static fn ($tag) => is_scalar($tag) && (string)$tag !== '');

		return implode(',', array_map(static fn ($tag) => (string)$tag, $scalarTags));
	}

	public static function parseTagsFromFields(array $params = []): array
	{
		$result = [];

		$blogCategoryIdList = ($params['blogCategoryIdList'] ?? []);
		$postFields = ($params['postFields'] ?? []);
		$blogId = (int)($params['blogId'] ?? 0);

		$existingTagList = [];

		if (!empty($blogCategoryIdList))
		{
			$res = \CBlogCategory::getList(
				[],
				[
					'@ID' => $blogCategoryIdList,
				],
				false,
				false,
				[ 'NAME' ]
			);
			while ($blogCategoryFields = $res->fetch())
			{
				$existingTagList[] = $blogCategoryFields['NAME'];
			}
		}

		$codeList = [ 'DETAIL_TEXT' ];
		if (
			!isset($postFields['MICRO'])
			|| $postFields['MICRO'] !== 'Y'
		)
		{
			$codeList[] = 'TITLE';
		}

		$inlineTagList = Util::detectTags($postFields, $codeList);

		$tagList = array_merge($existingTagList, $inlineTagList);
		$tagList = array_intersect_key($tagList, array_unique(array_map('ToLower', $tagList)));

		if (count($tagList) > count($existingTagList))
		{
			$lowerExistingTagList = array_unique(array_map('ToLower', $existingTagList));
			$newTagList = [];

			foreach ($inlineTagList as $inlineTag)
			{
				if (!in_array(mb_strtolower($inlineTag), $lowerExistingTagList, true))
				{
					$newTagList[] = $inlineTag;
				}
			}

			if (!empty($newTagList))
			{
				$newTagList = array_unique($newTagList);

				$existingCategoriesList = [];
				$res = \CBlogCategory::getList(
					[],
					[
						'@NAME' => $newTagList,
						'BLOG_ID' => $blogId,
					],
					false,
					false,
					[ 'ID', 'NAME' ]
				);
				while ($blogCategoryFields = $res->fetch())
				{
					$existingCategoriesList[$blogCategoryFields['NAME']] = (int)$blogCategoryFields['ID'];
				}

				foreach ($newTagList as $newTag)
				{
					if (isset($existingCategoriesList[$newTag]))
					{
						$result[] = $existingCategoriesList[$newTag];
					}
					else
					{
						$result[] = (int)\CBlogCategory::add([
							'BLOG_ID' => $blogId,
							'NAME' => $newTag,
						]);
					}
				}
			}
		}

		return $result;
	}
}
