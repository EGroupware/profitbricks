<?php
/**
 * EGroupware - Profitbricks - UI
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
				'actions'        => self::get_actions(),
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

		$etpl->exec('profitbricks.profitbricks_ui.index', $content, $sel_options, array(), array(
			'nm' => $content['nm'],
		));
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
			$rows = $readonlys = array();
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
	 * Run or schedule an action
	 *
	 * @param string $action see get_actions
	 * @param string|array $server id of server(s)
	 * @param int|array $schedule =null int. number of secs to run action from now or array with schedule information
	 */
	public static function ajax_action($action, $server, $schedule=null)
	{
		$response = Api\Json\Response::get();

		$matches = null;
		if (!isset($schedule) && preg_match('/^([a-z]+)_(\d+)$/', $action, $matches))
		{
			$action = $matches[1];
			$schedule = $matches[2];
		}

		if (isset($schedule))
		{
			$response->call('egw.message', 'Scheduling of '.$action.' for server '.implode(', ', (array)$server).' not yet implemented :-(');
			return;
		}

		$datacenter = $GLOBALS['egw_info']['user']['preferences']['profitbricks']['state']['filter'];

		foreach((array)$server as $server)
		{
			if (substr($server, 0, 14) == 'profitbricks::')
			{
				$server = substr($server, 14);
			}
			try {
				// fetch name of $server and other details like it's external IP
				$data = profitbricks_api::server($datacenter, $server, $action == 'dnsupdate' ? 2 : 1);
				$servername = empty($data['properties']['name']) ? $server : $data['properties']['name'];
				switch($action)
				{
					case 'start':
					case 'stop':
					case 'reboot':
						$headers = profitbricks_api::post("datacenters/$datacenter/servers/$server/$action");
						if ($headers[0] === 'HTTP/1.1 202 Accepted')
						{
							$response->call('egw.message', ucfirst($action).' of server '.$servername.' requested.');
						}
						else
						{
							$response->call('egw.message', ucfirst($action).' for server '.$servername.' failed: '.substr($headers[0], 9).'!', 'error');
						}
						break;

					case 'dnsupdate':
						foreach($data['entities']['nics']['items'] as $item)
						{
							if ($item['properties']['dhcp'] && count($item['properties']['ips']))
							{
								$ip = $item['properties']['ips'][0];
								break;
							}
						}
						$ret = profitbricks_dns::update($servername, $ip, $headers);
						list(, $http_status) = explode(' ', $headers[0], 2);
						$response->call('egw.message', ucfirst($action).' of server '.$servername.' to IP '.$ip.': '.
							($ret ? $ret : lang($ret === false ? 'Error '.$http_status : 'Success')),
							$ret === false ? 'error' : 'success');
						break;

					default:
						$response->call('egw.message', ucfirst($action).' server '.$servername.' not yet implemented :-(');
				}
			}
			catch (Api\Exception $e) {
				$response->call('egw.message', ucfirst($action).' for server '.$server.' failed: '.$e->getMessage(), 'error');
			}
		}
	}

	/**
	 * Actions on users
	 *
	 * @return array
	 */
	public static function get_actions()
	{
		static $actions = null;

		if (!isset($actions))
		{
			$actions = array(
				'start' => array(
					'caption' => 'Start',
					'allowOnMultiple' => false,
					'onExecute' => 'javaScript:app.profitbricks.action',
					'group' => $group=0,
					'icon' => 'tick',
				),
				'reboot' => array(
					'caption' => 'Reboot',
					'allowOnMultiple' => false,
					'onExecute' => 'javaScript:app.profitbricks.confirm',
					'group' => $group,
					'hint' => 'Server will be reset (not properly restarted)!',
					'icon' => 'discard',
				),
				'stop' => array(
					'caption' => 'Stop',
					'allowOnMultiple' => false,
					'onExecute' => 'javaScript:app.profitbricks.confirm',
					'group' => $group,
					'hint' => 'Server will be powered off (not shut down) and IP will change with next start!',
					'icon' => 'logout',
				),
				'stop_300' => array(
					'caption' => 'Stop in 5min',
					'allowOnMultiple' => false,
					'onExecute' => 'javaScript:app.profitbricks.confirm',
					'group' => $group,
					'hint' => 'Server will be powered off (not shut down) and IP will change with next start!',
					'icon' => 'k_alarm',
				),
				'dnsupdate' => array(
					'caption' => 'Update DNS',
					'hint' => 'Update DNS of name with current external IP',
					'allowOnMultiple' => false,
					'group' => $group=5,
					'onExecute' => 'javaScript:app.profitbricks.action',
					'icon' => 'edit',
				),
				'snapshot' => array(
					'caption' => 'Create snapshot',
					'allowOnMultiple' => false,
					'group' => ++$group,
					'icon' => 'export',
				),
				'schedule' => array(
					'caption' => 'Schedule an action',
					'onExecute' => 'javaScript:app.profitbricks.schedule',
					'group' => ++$group,
					'icon' => 'k_alarm',
				),
			);
		}
		//error_log(__METHOD__."() actions=".array2string($actions));
		return $actions;
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