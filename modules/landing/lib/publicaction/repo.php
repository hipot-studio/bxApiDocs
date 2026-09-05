<?php
namespace Bitrix\Landing\PublicAction;

use Bitrix\Landing;
use Bitrix\Landing\Error;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Site;
use Bitrix\Main\Localization\Loc;
use Bitrix\Rest\HandlerHelper;
use Bitrix\Rest\Marketplace\Client;
use Bitrix\Rest\PlacementTable;
use Bitrix\Landing\Placement;
use Bitrix\Landing\PublicActionResult;
use Bitrix\Landing\Node\StyleImg;

Loc::loadMessages(__FILE__);

class Repo
{
	/**
	 * Prefix of the placement codes served by this module.
	 */
	private const PLACEMENT_PREFIX = 'LANDING_';

	/**
	 * Check content for bad substring.
	 * @param string $content
	 * @param string $splitter
	 * @return PublicActionResult
	 */
	public static function checkContent($content, $splitter = '#SANITIZE#')
	{
		$result = new PublicActionResult();
		$bad = false;
		$content = (new Landing\Sanitizer())->sanitizeText(
			$content,
			$bad,
			$splitter
		);
		$result->setResult(array(
			'is_bad' => $bad,
			'content' => $content,
		));

		return $result;
	}

	/**
	 * Registers new block.
	 * @param string $code Unique code of block (unique within app).
	 * @param array $fields Block data.
	 * @param array $manifest Manifest data.
	 * @return PublicActionResult
	 */
	public static function register(string $code, array $fields, array $manifest = []): PublicActionResult
	{
		$result = new PublicActionResult();
		$error = new \Bitrix\Landing\Error;

		$app = \Bitrix\Landing\PublicAction::restApplication();
		if (!self::checkRepositoryAccess($app, $error))
		{
			$result->setError($error);

			return $result;
		}

		static::onRegisterCheckFields($fields, $error);
		static::onRegisterBefore($fields, $manifest, $error);
		if (!empty($error->getErrors()))
		{
			$result->setError($error);

			return $result;
		}

		// check intersect item of nodes and styles for background type
		if (is_array($manifest['nodes'] ?? null))
		{
			foreach ($manifest['nodes'] as $selector => $manifestItem)
			{
				$styleItem = null;

				if (isset($manifest['style'][$selector]))
				{
					$styleItem = $manifest['style'][$selector];
				}
				if (isset($manifest['style']['nodes'][$selector]))
				{
					$styleItem = $manifest['style']['nodes'][$selector];
				}

				if ($styleItem['type'] ?? null)
				{
					if (!empty(array_intersect((array)$styleItem['type'], StyleImg::STYLES_WITH_IMAGE)))
					{
						$error->addError(
							'MANIFEST_INTERSECT_IMG',
							Loc::getMessage('LANDING_APP_MANIFEST_INTERSECT_IMG', ['#selector#' => $selector])
						);
						$result->setError($error);

						return $result;
					}
				}
			}
		}

		$needReset = isset($fields['RESET']) && $fields['RESET'] == 'Y';

		$normalized = Landing\Security\RepoNormalizer::normalize(
			$fields,
			$manifest,
			trim($code),
			$app['CODE'] ?? null
		);
		if ($normalized->contentRejected)
		{
			self::addRejectedContentError($fields, $manifest, $error);
			$result->setError($error);

			return $result;
		}
		$fields = $normalized->fields;

		// check unique
		$exists = false;
		if ($fields['XML_ID'])
		{
			$exists = Landing\Repo::getList([
				'select' => ['ID'],
				'filter' => self::getRepositoryFilter($fields['XML_ID'], $app),
			])->fetch();
		}

		// register (add / update)
		if ($exists)
		{
			$res = Landing\Repo::update($exists['ID'], $fields);
		}
		else
		{
			$res = Landing\Repo::add($fields);
		}
		if ($res->isSuccess())
		{
			if ($needReset)
			{
				\Bitrix\Landing\Update\Block::register(
					'repo_' . $res->getId()
				);
			}
			$result->setResult($res->getId());
		}
		else
		{
			$error->addFromResult($res);
			$result->setError($error);
		}

		return $result;
	}

	/**
	 * Report the content the normalizer has rejected.
	 * The normalizer gives one verdict for the block content and for the card presets together,
	 * while the method answers with a separate error code for each, so the source of the rejection
	 * is located by normalizing the parts of the same data one by one.
	 * @param array $fields Incoming fields.
	 * @param array $manifest Manifest data.
	 * @param Error $error - object for set errors
	 * @return void
	 */
	private static function addRejectedContentError(array $fields, array $manifest, Error $error): void
	{
		$rejectedPreset = self::isContentRejected($fields, [])
			? null
			: self::findRejectedPreset($manifest)
		;

		if ($rejectedPreset === null)
		{
			$error->addError(
				'CONTENT_IS_BAD',
				Loc::getMessage('LANDING_APP_CONTENT_IS_BAD')
			);

			return;
		}

		$error->addError(
			'PRESET_CONTENT_IS_BAD',
			Loc::getMessage(
				'LANDING_APP_PRESET_CONTENT_IS_BAD',
				[
					'#preset#' => $rejectedPreset['preset'],
					'#card#' => $rejectedPreset['card'],
				]
			)
		);
	}

	/**
	 * Codes of the card and of the first preset within it the normalizer rejects.
	 * @param array $manifest Manifest data.
	 * @return array|null
	 */
	private static function findRejectedPreset(array $manifest): ?array
	{
		$cards = is_array($manifest['cards'] ?? null) ? $manifest['cards'] : [];

		foreach ($cards as $cardCode => $card)
		{
			$presets = is_array($card['presets'] ?? null) ? $card['presets'] : [];
			foreach ($presets as $presetCode => $preset)
			{
				$singlePreset = ['cards' => [$cardCode => ['presets' => [$presetCode => $preset]]]];
				if (self::isContentRejected([], $singlePreset))
				{
					return [
						'card' => $cardCode,
						'preset' => $presetCode,
					];
				}
			}
		}

		return null;
	}

	private static function isContentRejected(array $fields, array $manifest): bool
	{
		return Landing\Security\RepoNormalizer::normalize($fields, $manifest, '')->contentRejected;
	}

	/**
	 * Check rights for write access to the block repository.
	 * A REST application owns only its own items, an item without the application is portal-wide.
	 * @param array|null $app Current REST application.
	 * @param Error $error - object for set errors
	 * @return bool
	 */
	private static function checkRepositoryAccess(?array $app, Error $error): bool
	{
		if (isset($app['CODE']) || Landing\Rights::isAdmin())
		{
			return true;
		}

		$error->addError(
			'ACCESS_DENIED',
			Loc::getMessage('LANDING_APP_REPO_ACCESS_DENIED')
		);

		return false;
	}

	/**
	 * Filter of the repository items available to the caller.
	 * @param string $xmlId Unique code of block.
	 * @param array|null $app Current REST application.
	 * @return array
	 */
	private static function getRepositoryFilter(string $xmlId, ?array $app): array
	{
		return [
			'=XML_ID' => $xmlId,
			'=APP_CODE' => $app['CODE'] ?? false,
		];
	}

	/**
	 * Check required fields
	 * @param $fields
	 * @param Error $error - object for set errors
	 * @return void
	 */
	protected static function onRegisterCheckFields($fields, Error $error): void
	{
		$requiredFields = [
			'NAME',
			'CONTENT',
			'SECTIONS',
			'PREVIEW',
		];

		foreach ($requiredFields as $field)
		{
			if (!isset($fields[$field]))
			{
				$error->addError(
					'REQUIRED_FIELD_NO_EXISTS',
					Loc::getMessage('LANDING_FIELD_NO_EXISTS', ['#field#' => $field])
				);
			}
		}
	}

	/**
	 * Some fixes in fields and manifest, specific by scope (mainpage widget or any)
	 * @param array $fields
	 * @param array $manifest
	 * @param Error $error - object for set errors
	 * @return array
	 */
	protected static function onRegisterBefore(array &$fields, array &$manifest, Error $error): void
	{
		// unset not allowed site types
		if (isset($manifest['block']['type']))
		{
			$manifest['block']['type'] = array_filter(
				(array)$manifest['block']['type'],
				function ($type) use ($error) {
					$notAllowedBlockTypes = [
						Site\Type::SCOPE_CODE_VIBE,
					];
					if (in_array(mb_strtolower($type), $notAllowedBlockTypes))
					{
						$error->addError(
							'UNSUPPORTED_BLOCK_TYPE',
							Loc::getMessage('LANDING_UNSUPPORTED_BLOCK_TYPE', ['#type#' => $type])
						);

						return false;
					}
					return true;
				}
			);
		}

		// unset not allowed subtypes
		if (isset($manifest['block']['subtype']))
		{
			$manifest['block']['subtype'] = array_filter(
				(array)$manifest['block']['subtype'],
				function ($type) use ($error) {
					// a widget is registered by its own action only, where its handler is checked
					$notAllowedSubtypes = [
						'widget',
						'widgetvue',
					];
					if (in_array(mb_strtolower($type), $notAllowedSubtypes))
					{
						$error->addError(
							'UNSUPPORTED_BLOCK_SUBTYPE',
							Loc::getMessage('LANDING_UNSUPPORTED_BLOCK_SUBTYPE', ['#type#' => $type])
						);

						return false;
					}
					return true;
				}
			);
		}
	}

	/**
	 * Unregister block.
	 * @param string $code Code of block.
	 * @return \Bitrix\Landing\PublicActionResult
	 */
	public static function unregister($code)
	{
		$result = new PublicActionResult();
		$error = new \Bitrix\Landing\Error;

		$result->setResult(false);

		if (!is_string($code))
		{
			return $result;
		}

		$app = \Bitrix\Landing\PublicAction::restApplication();
		if (!self::checkRepositoryAccess($app, $error))
		{
			$result->setError($error);

			return $result;
		}

		// search and delete
		if ($code)
		{
			$row = Landing\Repo::getList(array(
				'select' => array(
					'ID',
				),
				'filter' => self::getRepositoryFilter($code, $app),
			))->fetch();
			if ($row)
			{
				// delete all sush blocks from landings
				$codeToDelete = array();
				$res = Landing\Repo::getList(array(
					'select' => array(
						'ID',
					),
					'filter' => self::getRepositoryFilter($code, $app),
				));
				while ($rowRepo = $res->fetch())
				{
					$codeToDelete[] = 'repo_' . $rowRepo['ID'];
				}
				if (!empty($codeToDelete))
				{
					Landing\Block::deleteByCode($codeToDelete);
				}
				// delete block from repo
				$res = Landing\Repo::delete($row['ID']);
				if ($res->isSuccess())
				{
					$result->setResult(true);
				}
				else
				{
					$error->addFromResult($res);
				}
			}
		}

		$result->setError($error);

		return $result;
	}

	/**
	 * Get info about app from Repo.
	 * @param string $code App code.
	 * @return \Bitrix\Landing\PublicActionResult
	 */
	public static function getAppInfo($code)
	{
		$result = new PublicActionResult();
		$error = new \Bitrix\Landing\Error;
		$app = array();

		if (!is_string($code))
		{
			return $result;
		}

		if ($appLocal = Landing\Repo::getAppByCode($code))
		{
			$app = array(
				'CODE' => $appLocal['CODE'],
				'NAME' => $appLocal['APP_NAME'],
				'DATE_FINISH' => (string)$appLocal['DATE_FINISH'],
				'PAYMENT_ALLOW' => $appLocal['PAYMENT_ALLOW'],
				'ICON' => '',
				'PRICE' => array(),
				'UPDATES' => 0,
			);
			if (\Bitrix\Main\Loader::includeModule('rest'))
			{
				$appRemote = Client::getApp($code);
				if (isset($appRemote['ITEMS']))
				{
					$data = $appRemote['ITEMS'];
					if (isset($data['ICON']))
					{
						$app['ICON'] = $data['ICON'];
					}
					if (isset($data['PRICE']) && !empty($data['PRICE']))
					{
						$app['PRICE'] = $data['PRICE'];
					}
				}
				$updates = Client::getUpdates(array(
					$code => $appLocal['VERSION'],
				));
				if (
					isset($updates['ITEMS'][0]['VERSIONS']) &&
					is_array($updates['ITEMS'][0]['VERSIONS'])
				)
				{
					$app['UPDATES'] = count($updates['ITEMS'][0]['VERSIONS']);
				}
			}
			$result->setResult($app);
		}

		if (empty($app))
		{
			$error->addError(
				'NOT_FOUND',
				Loc::getMessage('LANDING_APP_NOT_FOUND')
			);
		}

		$result->setError($error);

		return $result;
	}

	/**
	 * Bind the placement.
	 * @param array $fields Fields array.
	 * @return \Bitrix\Landing\PublicActionResult
	 */
	public static function bind(array $fields)
	{
		$result = new PublicActionResult();
		$error = new \Bitrix\Landing\Error;

		$app = \Bitrix\Landing\PublicAction::restApplication();
		if (!isset($app['ID']) || !\Bitrix\Main\Loader::includeModule('rest'))
		{
			$error->addError(
				'ACCESS_DENIED',
				Loc::getMessage('LANDING_APP_PLACEMENT_ACCESS_DENIED')
			);
			$result->setError($error);

			return $result;
		}

		$fields = self::preparePlacementFields($fields, $app, $error);
		if (!$error->isEmpty())
		{
			$result->setError($error);

			return $result;
		}

		$res = Placement::getList(array(
			'select' => array(
				'ID',
			),
			'filter' => array(
				'APP_ID' => $fields['APP_ID'],
				'PLACEMENT' => $fields['PLACEMENT'],
				'PLACEMENT_HANDLER' => $fields['PLACEMENT_HANDLER'],
			),
		));
		// add, if not exist
		if (!$res->fetch())
		{
			// first try add in the local table
			$resLocal = Placement::add($fields);
			if ($resLocal->isSuccess())
			{
				// then add in the rest table
				$restFields = $fields;
				$restFields['USER_ID'] = PlacementTable::DEFAULT_USER_ID_VALUE;
				$resRest = PlacementTable::add($restFields);
				if ($resRest->isSuccess())
				{
					$result->setResult(true);
				}
				else
				{
					$error->addFromResult($resRest);
					Placement::delete($resLocal->getId());
				}
			}
			else
			{
				$error->addFromResult($resLocal);
			}
		}
		else
		{
			$error->addError(
				'PLACEMENT_EXIST',
				Loc::getMessage('LANDING_APP_PLACEMENT_EXIST')
			);
		}

		$result->setError($error);

		return $result;
	}

	/**
	 * Prepare fields for the placement row.
	 * The placement is shown in the admin interface, so the owner application, the target user and
	 * the set of the fields are defined here and not by the caller.
	 * @param array $fields Incoming fields.
	 * @param array $app Current REST application.
	 * @param Error $error - object for set errors
	 * @return array
	 */
	private static function preparePlacementFields(array $fields, array $app, Error $error): array
	{
		$placement = is_string($fields['PLACEMENT'] ?? null)
			? mb_strtoupper(trim($fields['PLACEMENT']))
			: '';
		$handler = is_string($fields['PLACEMENT_HANDLER'] ?? null)
			? trim($fields['PLACEMENT_HANDLER'])
			: '';
		$title = is_string($fields['TITLE'] ?? null)
			? trim($fields['TITLE'])
			: '';

		if (!str_starts_with($placement, self::PLACEMENT_PREFIX))
		{
			$error->addError(
				'PLACEMENT_UNKNOWN',
				Loc::getMessage('LANDING_APP_PLACEMENT_UNKNOWN')
			);
		}

		try
		{
			HandlerHelper::checkCallback($handler, $app);
		}
		catch (\Throwable)
		{
			$error->addError(
				'PLACEMENT_HANDLER_INVALID',
				Loc::getMessage('LANDING_APP_PLACEMENT_HANDLER_INVALID')
			);
		}

		return [
			'APP_ID' => (int)$app['ID'],
			'PLACEMENT' => $placement,
			'PLACEMENT_HANDLER' => $handler,
			'TITLE' => $title,
		];
	}

	/**
	 * Unbind the placement.
	 * @param string $code Placement code.
	 * @param string $handler Handler path (if you want delete specific).
	 * @return \Bitrix\Landing\PublicActionResult
	 */
	public static function unbind($code, $handler = null)
	{
		$result = new PublicActionResult();
		$error = new \Bitrix\Landing\Error;

		if (!is_string($code))
		{
			return $result;
		}

		$code = trim($code);
		$wasDeleted = false;

		if (($app = \Bitrix\Landing\PublicAction::restApplication()))
		{
			$fields['APP_ID'] = $app['ID'];
		}
		if (
			!isset($fields['APP_ID']) ||
			!$fields['APP_ID']
		)
		{
			return $result;
		}

		// common ORM params
		$params = [
			'select' => [
				'ID',
			],
			'filter' => [
				'APP_ID' => $fields['APP_ID'],
				'=PLACEMENT' => $code,
			],
		];
		if ($handler)
		{
			$params['filter']['=PLACEMENT_HANDLER'] = trim($handler);
		}

		// at first, delete local binds
		$res = Placement::getList($params);
		while ($row = $res->fetch())
		{
			$wasDeleted = true;
			Placement::delete($row['ID']);
		}
		unset($res, $row);

		// then delete from rest placements
		if (\Bitrix\Main\Loader::includeModule('rest'))
		{
			$res = PlacementTable::getList($params);
			while ($row = $res->fetch())
			{
				PlacementTable::delete($row['ID']);
			}
			unset($res, $row);
		}

		// make answer
		if ($wasDeleted)
		{
			$result->setResult(true);
		}
		else
		{
			$error->addError(
				'PLACEMENT_NO_EXIST',
				Loc::getMessage('LANDING_APP_PLACEMENT_NO_EXIST')
			);
			$result->setError($error);
		}

		return $result;
	}

	/**
	 * Get items of current app.
	 * @param array $params Params ORM array.
	 * @return \Bitrix\Landing\PublicActionResult
	 */
	public static function getList(array $params = array()): PublicActionResult
	{
		$result = new PublicActionResult();
		$params = $result->sanitizeKeys($params);

		if (!is_array($params))
		{
			$params = [];
		}
		if (
			!isset($params['filter']) ||
			!is_array($params['filter'])
		)
		{
			$params['filter'] = array();
		}
		// set app code
		if (($app = \Bitrix\Landing\PublicAction::restApplication()))
		{
			$params['filter']['APP_CODE'] = $app['CODE'];
		}
		else
		{
			$params['filter']['APP_CODE'] = false;
		}

		// manifest always needed
		if (isset($params['select']))
		{
			$params['select'][] = 'MANIFEST';
		}

		$data = [];
		$res = Landing\Repo::getList($params);

		while ($row = $res->fetch())
		{
			if (isset($row['DATE_CREATE']))
			{
				$row['DATE_CREATE'] = (string) $row['DATE_CREATE'];
			}
			if (isset($row['DATE_MODIFY']))
			{
				$row['DATE_MODIFY'] = (string) $row['DATE_MODIFY'];
			}
			$row['MANIFEST'] = unserialize($row['MANIFEST'], ['allowed_classes' => false]);
			$data[] = $row;
		}
		$result->setResult($data);

		return $result;
	}
}
