<?php
namespace Bitrix\Landing;

use \Bitrix\Landing\Internals\TemplateRefTable;

class TemplateRef
{
	/**
	 * Entity type site.
	 */
	const ENTITY_TYPE_SITE = 'S';

	/**
	 * Entity type landing.
	 */
	const ENTITY_TYPE_LANDING = 'L';

	private const CACHE_DIR = '/landing/is_area/';

	/**
	 * Set new template refs for entity.
	 * @param int $id Entity id.
	 * @param string $type Entity type.
	 * @param array $data Ref array.
	 * @return void
	 */
	protected static function set($id, $type, array $data = array())
	{
		$id = intval($id);
		$rows = TemplateRefTable::getList(array(
			'select' => array(
				'ID', 'AREA', 'LANDING_ID'
			),
			'filter' => array(
				'ENTITY_ID' => $id,
				'=ENTITY_TYPE' => $type
			)
		))->fetchAll();
		$data = self::removeForbiddenTargets($data, array_column($rows, 'LANDING_ID', 'AREA'));
		foreach ($rows as $row)
		{
			if (isset($data[$row['AREA']]) && $data[$row['AREA']] > 0)
			{
				if ($row['LANDING_ID'] != $data[$row['AREA']])
				{
					TemplateRefTable::update($row['ID'], array(
						'LANDING_ID' => $data[$row['AREA']]
					));
					BXClearCache(true, self::CACHE_DIR);
				}
				unset($data[$row['AREA']]);
			}
			else
			{
				TemplateRefTable::delete($row['ID']);
				BXClearCache(true, self::CACHE_DIR);
			}
		}
		foreach ($data as $area => $lid)
		{
			if ($lid > 0)
			{
				TemplateRefTable::add(array(
					'ENTITY_ID' => $id,
					'ENTITY_TYPE' => $type,
					'LANDING_ID' => $lid,
					'AREA' => $area
				));
				BXClearCache(true, self::CACHE_DIR);
			}
		}
	}

	/**
	 * Areas are rendered with the rights check off, so a new target must be a page the current user can read.
	 * Becoming an area also closes the public address of a page and forbids deleting it, so a page that is not
	 * an area yet needs the settings right. A target already stored for the area is kept: an editor re-saving
	 * the settings must not break the template.
	 * @param array $data Ref array (area => landing).
	 * @param array $stored Stored refs (area => landing).
	 * @return array
	 */
	private static function removeForbiddenTargets(array $data, array $stored): array
	{
		$newTargets = [];
		foreach ($data as $area => $lid)
		{
			if ($lid > 0 && (int)$lid !== (int)($stored[$area] ?? 0))
			{
				$newTargets[$area] = (int)$lid;
			}
		}
		if (!$newTargets)
		{
			return $data;
		}

		$allowed = [];
		$res = Landing::getList([
			'select' => ['ID'],
			'filter' => [
				'ID' => array_values(array_unique($newTargets)),
				'=DELETED' => ['Y', 'N'],
				'=SITE.DELETED' => ['Y', 'N'],
			],
		]);
		while ($row = $res->fetch())
		{
			$lid = (int)$row['ID'];
			if (
				!Rights::isOn()
				|| self::landingIsArea($lid)
				|| Rights::hasAccessForLanding($lid, Rights::ACCESS_TYPES['sett'])
			)
			{
				$allowed[$lid] = true;
			}
		}

		foreach ($newTargets as $area => $lid)
		{
			if (!isset($allowed[$lid]))
			{
				unset($data[$area]);
			}
		}

		return $data;
	}

	/**
	 * Get template refs for entity.
	 * @param int $id Entity id.
	 * @param string $type Entity type.
	 * @return array
	 */
	protected static function get($id, $type)
	{
		static $staticData = array();
		$id = intval($id);

		if (!isset($staticData[$type . $id]))
		{
			$data = array();
			if ($id > 0)
			{
				$res = TemplateRefTable::getList(array(
					'select' => array(
						'AREA', 'LANDING_ID'
					),
					'filter' => array(
						'ENTITY_ID' => $id,
						'=ENTITY_TYPE' => $type
					)
				));
				while (($row = $res->fetch()))
				{
					$data[$row['AREA']] = $row['LANDING_ID'];
				}
			}

			$staticData[$type . $id] = $data;
		}

		return $staticData[$type . $id];
	}

	/**
	 * Set new template refs for site.
	 * @param int $id Site id.
	 * @param array $data Ref array (area => landing).
	 * @return void
	 */
	public static function setForSite($id, array $data = array())
	{
		if (Rights::hasAccessForSite($id, Rights::ACCESS_TYPES['sett']))
		{
			self::set($id, self::ENTITY_TYPE_SITE, $data);
		}
	}

	/**
	 * Set new template refs for landing.
	 * @param int $id Landing id.
	 * @param array $data Ref array (area => landing).
	 * @return void
	 */
	public static function setForLanding($id, array $data = array())
	{
		if (Rights::hasAccessForLanding($id, Rights::ACCESS_TYPES['sett']))
		{
			self::set($id, self::ENTITY_TYPE_LANDING, $data);
		}
	}

	/**
	 * Get template refs for site.
	 * @param int $id Site id.
	 * @return array
	 */
	public static function getForSite($id)
	{
		return self::get($id, self::ENTITY_TYPE_SITE);
	}

	/**
	 * Get template refs for site.
	 * @param int $id Landing id.
	 * @return array
	 */
	public static function getForLanding($id)
	{
		return self::get($id, self::ENTITY_TYPE_LANDING);
	}

	/**
	 * This landing id is used as a area?
	 *
	 * @param int|array $lid Landing id.
	 *
	 * @return boolean|array
	 */
	public static function landingIsArea(int|array $lid): bool|array
	{
		$cache = new \CPHPCache();
		$cacheTime = 3600;
		$cacheId = is_array($lid) ? md5(serialize($lid)) : (int)$lid;

		if ($cache->InitCache($cacheTime, $cacheId, self::CACHE_DIR))
		{
			$result = $cache->GetVars();
		}
		else
		{
			$cache->StartDataCache();

			$res = TemplateRefTable::getList([
				'filter' => [
					'LANDING_ID' => $lid,
				],
			]);

			if (is_array($lid))
			{
				$result = [];
				foreach ($lid as $id)
				{
					$result[(int)$id] = false;
				}
				while ($row = $res->fetch())
				{
					$result[$row['LANDING_ID']] = true;
				}
			}
			else
			{
				$result = (bool)$res->fetch();
			}

			$cache->EndDataCache($result);
		}

		return $result;
	}

	/**
	 * Delete all area-landing by id.
	 * @param integer $lid Landing id.
	 * @return array
	 */
	public static function deleteArea($lid)
	{
		$lid = intval($lid);
		$areas = array();

		$res = TemplateRefTable::getList(array(
			'filter' => array(
				'LANDING_ID' => $lid
			)
		));
		while ($row = $res->fetch())
		{
			$areas[] = array_diff_key($row, array('ID' => true));
			TemplateRefTable::delete($row['ID']);
			BXClearCache(true, self::CACHE_DIR);
		}

		return $areas;
	}

	/**
	 * Restore area-landings.
	 * @param array $areas Template refs.
	 * @return void
	 */
	public static function restoreArea(array $areas)
	{
		foreach ($areas as $area)
		{
			TemplateRefTable::add($area);
			BXClearCache(true, self::CACHE_DIR);
		}
	}

	/**
	 * Resolves class by type.
	 * @param string $type Entity type.
	 * @return string
	 */
	public static function resolveClassByType($type)
	{
		if ($type == self::ENTITY_TYPE_SITE)
		{
			return '\Bitrix\Landing\Site';
		}
		else if ($type == self::ENTITY_TYPE_LANDING)
		{
			return '\Bitrix\Landing\Landing';
		}
		return '';
	}
}
