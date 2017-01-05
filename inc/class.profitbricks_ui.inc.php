<?php
/**
 * EGroupware - Profitbricks - API
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <rb-AT-egroupware.org>
 * @package profitbricks
 * @copyright (c) 2017 by Ralf Becker <rb-AT-egroupware.org>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */


use EGroupware\Api;
use EGroupware\Api\Etemplate;
use EGroupware\Api\Egw;

class profitbricks_ui
{
	/**
	 * methods callable via menuaction
	 * @var array
	 */
	public $public_functions = array(
		'index' => true,
	);

	/**
	 * Show list of servers
	 *
	 * @param array $content =null
	 */
	function index(array $content=null)
	{
		$etpl = new Etemplate('profitbricks.index');

		if (!is_array($content))
		{
			$content['nm'] = (array)$GLOBALS['egw_info']['user']['preferences']['profitbricks']['state'];
			$content['nm'] += array(
				'get_rows'       =>	'profitbricks_ui::get_rows',
				'no_filter2'     => true,
				'no_cat'         => true,
				'order'          =>	'modified',// IO name of the column to sort after (optional for the sortheaders)
				'sort'           =>	'DESC',// IO direction of the sort: 'ASC' or 'DESC'
				'row_id'         => 'id',
				'row_modified'   => 'modified',
				//'actions'        => self::get_actions(),
				'default_cols'   => '!id',
				//'placeholder_actions' => array('add')
			);
		}
		$sel_options = array(
			'filter' => array(''=>'Select one ...')+profitbricks_api::datacenters(true),
			'properties[vmState]' => array(
				'RUNNING' => 'running',
			),
		);

		$etpl->exec('profitbricks.profitbricks_ui.index', $content, $sel_options);
	}

	/**
	 * query servers for nextmatch
	 *
	 * @param array &$query
	 * @param array &$rows returned rows
	 * @param array &$readonlys eg. to disable buttons based on Acl
	 * @return int total number of contacts matching the selection
	 */
	static function get_rows($query, &$rows, &$readonlys)
	{
		if (empty($query['filter']))	// no datacenter selected
		{
			$rows = array();
			return 0;
		}
		$state = array_intersect_key($query, array_flip(array('filter','order','sort')));
		if ($state != $GLOBALS['egw_info']['user']['preferences']['profitbricks']['state'])
		{
			$GLOBALS['egw']->preferences->add('profitbricks', 'state', $GLOBALS['egw_info']['user']['preferences']['profitbricks']['state']=$state);
			$GLOBALS['egw']->preferences->save_repository(true);
		}
		$rows = profitbricks_api::servers($query['filter'], 3);
		foreach($rows as &$row)
		{
			$row['modified'] = Api\DateTime::server2user(
				DateTime::createFromFormat(DateTime::ISO8601, $row['metadata']['lastModifiedDate']), 'ts');

			$row['ips'] = array();
			foreach($row['entities']['nics']['items'] as $nic)
			{
				if ($nic['properties']['dhcp']) $row['ips'] += $nic['properties']['ips'];
			}
			$row['ips'] = implode(",\n", $row['ips']);

			// show number of nics, volumns, ...
			foreach($row['entities'] as $name => $entity)
			{
				$row[$name] = count($entity['items']);
			}

			// calculate size of volumes
			$size = 0;
			foreach($row['entities']['volumes']['items'] as $volum)
			{
				$size += $volum['properties']['size'];
			}
			$row['volumes'] .= ': '.$size;

			// remove not (directly) used entities
			unset($row['entities']);
		}

		usort($rows, function($a, $b) use ($query)
		{
			$sign = $query['sort'] == 'DESC' ? -1 : 1;

			// process indexes like "properties[name]"
			foreach(explode('[', str_replace(']', '', $query['order'])) as $key)
			{
				$a = $a[$key];
				$b = $b[$key];
			}

			switch($query['order'])
			{
				case 'id':
				case 'properties[name]':
				case 'ips':
					return $sign * strcasecmp($a, $b);

				case 'volumes':
					list(, $a) = explode(': ', $a);
					list(, $b) = explode(': ', $b);
					// fall through
				default:
					return $sign * ($a - $b);
			}
		});

		return count($rows);
	}

	/**
	 * Hook for sidebox and admin
	 *
	 * @param array $args
	 */
	public static function hooks($args)
	{
		$appname = 'profitbricks';
		$location = is_array($args) ? $args['location'] : $args;

		if ($GLOBALS['egw_info']['user']['apps']['admin'])
		{
			$file = Array(
				'Site Configuration' => Egw::link('/index.php','menuaction=admin.admin_config.index&appname=' . $appname,'&ajax=true'),
			);
			if ($location == 'admin')
			{
				display_section($appname, $file);
			}
			else
			{
				display_sidebox($appname, lang('Admin'), $file);
			}
		}
	}
}