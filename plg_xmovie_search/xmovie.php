<?php
/*
 * @package Joomla 3.0
 * @copyright Copyright (C) 2005 Open Source Matters. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 *
 * @component XMovie Component
 * @copyright Copyright (C) Dana Harris optikool.com
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */
defined('_JEXEC') or die('Restricted access');

require_once JPATH_SITE.'/components/com_xmovie/helpers/movie.php';
require_once JPATH_SITE . '/components/com_xmovie/router.php';

/**
 * XMovie Search Plugin
 *
 * @package		Joomla.Plugin
 * @subpackage	Search.xmovie
 * @since		2.5
 */

jimport('joomla.plugin.plugin');

class plgSearchXMovie extends JPlugin {
	protected $autoloadLanguage = true;	
	
	/**
	 * @return array An array of search areas
	 */
	function onContentSearchAreas() {
		static $areas = array(
			'xmovie' => 'PLG_SEARCH_XMOVIE_MOVIES'
			);
			return $areas;
	}
	
	/**
	 * XMovie Search method
	 *
	 * The sql must return the following fields that are used in a common display
	 * routine: href, title, section, creation_date, text, browsernav
	 * @param string Target search string
	 * @param string mathcing option, exact|any|all
	 * @param string ordering option, newest|oldest|popular|alpha|category
	 * @param mixed An array if the search it to be restricted to areas, null if search all
	 */
	function onContentSearch($text, $phrase='', $ordering='', $areas=null) {
		$db		= JFactory::getDbo();
		$app	= JFactory::getApplication();
		$user	= JFactory::getUser();
		$groups	= implode(',', $user->getAuthorisedViewLevels());
		$tag = JFactory::getLanguage()->getTag();
		
		require_once JPATH_ADMINISTRATOR . '/components/com_search/helpers/search.php';

		$searchText = $text;

		if (is_array($areas)) {
			if (!array_intersect($areas, array_keys($this->onContentSearchAreas()))) {
				return array();
			}
		}

		$sContent		= $this->params->get('search_content',		1);
		$sArchived		= $this->params->get('search_archived',		1);
		$limit			= $this->params->def('search_limit',		50);
		$state = array();
		if ($sContent) {
			$state[]=1;
		}
		if ($sArchived) {
			$state[]=2;
		}

		$text = trim($text);
		if ($text == '') {
			return array();
		}
		$section	= JText::_('PLG_SEARCH_XMOVIE_MOVIES');

		$wheres	= array();
		switch ($phrase) {
			case 'exact':
				$text		= $db->Quote('%'.$db->escape($text, true).'%', false);
				$wheres2	= array();
				$wheres2[] =  'a.introtext LIKE '.$text;
				$wheres2[] =  'a.fulltext LIKE '.$text;
				$wheres2[] =  'a.title LIKE '.$text;
				$wheres2[] =  'a.metakey LIKE '.$text;
				$wheres2[] =  'a.metadesc LIKE '.$text;
				$where		= '(' . implode(') OR (', $wheres2) . ')';
				break;

			case 'all':
			case 'any':
			default:
				$words	= explode(' ', $text);
				$wheres = array();
				foreach ($words as $word) {
					$word		= $db->Quote('%'.$db->escape($word, true).'%', false);
					$wheres2	= array();
					$wheres2[] = 'a.title LIKE ' . $word;
					$wheres2[] = 'a.introtext LIKE ' . $word;
					$wheres2[] = 'a.fulltext LIKE ' . $word;
					$wheres2[] = 'a.metakey LIKE ' . $word;
					$wheres2[] = 'a.metadesc LIKE ' . $word;
					$wheres[]	= implode(' OR ', $wheres2);
				}
				$where	= '(' . implode(($phrase == 'all' ? ') AND (' : ') OR ('), $wheres) . ')';
				break;
		}

		switch ($ordering) {
			case 'oldest':
				$order = 'a.creation_date ASC';
				break;

			case 'popular':
				$order = 'a.hits DESC';
				break;

			case 'alpha':
				$order = 'a.title ASC';
				break;

			case 'category':
				$order = 'c.title ASC, a.title ASC';
				break;

			case 'newest':
			default:
				$order = 'a.creation_date DESC';
		}
		
		$return = array();
		if (!empty($state)) {
			$query	= $db->getQuery(true);
	        //sqlsrv changes
	        $case_when = ' CASE WHEN ';
	        $case_when .= $query->charLength('a.alias');
	        $case_when .= ' THEN ';
	        $a_id = $query->castAsChar('a.id');
	        $case_when .= $query->concatenate(array($a_id, 'a.alias'), ':');
	        $case_when .= ' ELSE ';
	        $case_when .= $a_id.' END as slug';

	        $case_when1 = ' CASE WHEN ';
	        $case_when1 .= $query->charLength('c.alias');
	        $case_when1 .= ' THEN ';
	        $c_id = $query->castAsChar('c.id');
	        $case_when1 .= $query->concatenate(array($c_id, 'c.alias'), ':');
	        $case_when1 .= ' ELSE ';
	        $case_when1 .= $c_id.' END as catslug';
			
			$query->select('a.title AS title, a.introtext AS text, a.creation_date AS created, a.link AS url, '
						.$case_when.','.$case_when1.', '
						.'c.title AS section, \'2\' AS browsernav');
			$query->from('#__xmovie_movies AS a');
			$query->innerJoin('#__categories AS c ON c.id = a.catid');
			$query->where('('.$where.')' . ' AND a.published in ('.implode(',', $state).') AND  c.published=1 AND  c.access IN ('.$groups.')');
			$query->order($order);
			
			// Filter by language
			if ($app->isSite() && $app->getLanguageFilter()) {
				$tag = JFactory::getLanguage()->getTag();
				$query->where('a.language in (' . $db->Quote($tag) . ',' . $db->Quote('*') . ')');
				$query->where('c.language in (' . $db->Quote($tag) . ',' . $db->Quote('*') . ')');
			}
			
			$db->setQuery($query, 0, $limit);
			$rows = $db->loadObjectList();
		
			$return = array();
			if ($rows) {
				foreach($rows as $key => $row) {
					$rows[$key]->href =  MovieHelper::getXMovieRoute($row->slug, $row->catslug);
				}

				foreach($rows as $key => $movielink) {
					if (searchHelper::checkNoHTML($movielink, $searchText, array('link', 'text', 'title'))) {
						$return[] = $movielink;
					}
				}
			}
		}
		
		return $return;
	}
}
?>