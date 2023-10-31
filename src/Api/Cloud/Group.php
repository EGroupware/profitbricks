<?php
/**
 * EGroupware - IONOS Cloud API user(s) class
 *
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb-AT-egroupware.org>
 * @package profitbricks
 * @copyright (c) 2017-23 by Ralf Becker <rb-AT-egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Profitbricks\Api\Cloud;

class Group extends Base
{
	const BASE = 'um/groups';
    const UNIQ_ATTR = 'name';

	protected string $name;
	protected bool	$createDataCenter=false;
	protected bool	$createSnapshot=false;
	protected bool	$reserveIp=false;
	protected bool	$accessActivityLog=false;
	protected bool	$createPcc=false;
	protected bool	$s3Privilege=false;
	protected bool	$createBackupUnit=false;
	protected bool	$createInternetAccess=false;
	protected bool	$createK8sCluster=false;
	protected bool	$createFlowLog=false;
	protected bool	$accessAndManageMonitoring=false;
	protected bool	$accessAndManageCertificates=false;
	protected bool	$manageDBaaS=false;
	protected bool	$accessAndManageDns=false;
	protected bool	$manageRegistry=false;
	protected bool	$manageDataplatform=false;

	static protected array $properties = [
		'name' => 'string',
		'createDataCenter' => '?bool',
		'createSnapshot' => '?bool',
		'reserveIp' => '?bool',
		'accessActivityLog' => '?bool',
		'createPcc' => '?bool',
		's3Privilege' => '?bool',
		'createBackupUnit' => '?bool',
		'createInternetAccess' => '?bool',
		'createK8sCluster' => '?bool',
		'createFlowLog' => '?bool',
		'accessAndManageMonitoring' => '?bool',
		'accessAndManageCertificates' => '?bool',
		'manageDBaaS' => '?bool',
		'accessAndManageDns' => '?bool',
		'manageRegistry' => '?bool',
		'manageDataplatform' => '?bool',
	];
	static protected array $defaults = [
		'createDataCenter' => false,
		'createSnapshot' => false,
		'reserveIp' => false,
		'accessActivityLog' => false,
		'createPcc' => false,
		's3Privilege' => false,
		'createBackupUnit' => false,
		'createInternetAccess' => false,
		'createK8sCluster' => false,
		'createFlowLog' => false,
		'accessAndManageMonitoring' => false,
		'accessAndManageCertificates' => false,
		'manageDBaaS' => false,
		'accessAndManageDns' => false,
		'manageRegistry' => false,
		'manageDataplatform' => false,
	];

	/**
	 * Add user as member to the group
	 *
	 * @link https://api.ionos.com/docs/cloud/v6/#tag/User-management/operation/umGroupsUsersPost
	 * @param User $user
	 * @return void
	 */
	public function addMember(User $user)
	{
		self::call(self::BASE."/$this->id/users", [], [], 'POST', $user);
	}
}