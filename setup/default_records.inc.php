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

// give Admins group rights for Profitbricks app
$admingroup = $GLOBALS['egw_setup']->add_account('Admins', 'Admin', 'Group', False, False);
$GLOBALS['egw_setup']->add_acl('profitbricks', 'run', $admingroup);
