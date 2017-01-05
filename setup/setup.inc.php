<?php
/**
 * EGroupware - Profitbricks - setup definitions
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <rb-AT-egroupware.org>
 * @package profitbricks
 * @subpackage setup
 * @copyright (c) 2017 by Ralf Becker <rb-AT-egroupware.org>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

$setup_info['profitbricks']['name']      = 'profitbricks';
$setup_info['profitbricks']['version']   = '16.1';
$setup_info['profitbricks']['app_order'] = 5;
$setup_info['profitbricks']['tables']    = array();
$setup_info['profitbricks']['enable']    = 1;
$setup_info['profitbricks']['index']     = 'profitbricks.profitbricks_ui.index&ajax=true';

// never install by default, only via setup
$setup_info['profitbricks']['only_db'] = array('never');

$setup_info['profitbricks']['author'] =
$setup_info['profitbricks']['maintainer'] = array(
	'name'  => 'Ralf Becker',
	'email' => 'rb@egroupwae.org'
);
$setup_info['profitbricks']['license']  = 'GPL';
$setup_info['profitbricks']['description'] =
	'Manage servers in profitbricks datacenters';
$setup_info['profitbricks']['note'] = '';

// The hooks this app includes, needed for hooks registration
$setup_info['profitbricks']['hooks']['admin'] = 'profitbricks_ui::hooks';
$setup_info['profitbricks']['hooks']['sidebox_menu'] = 'profitbricks_ui::hooks';

// Dependencies for this app to work
$setup_info['profitbricks']['depends'][] = array(
	 'appname' => 'api',
	 'versions' => Array('16.1')
);
