<?php

/**
 * DNEnvironment
 *
 * This dataobject represents a target environment that source code can be deployed to.
 * Permissions are controlled by environment, see the various many-many relationships.
 *
 */
class DNEnvironment extends DataObject {

	/**
	 * If this is set to a full pathfile, it will be used as template
	 * file when creating a new capistrano environment config file.
	 * 
	 * If not set, the default 'environment.template' from the module 
	 * root is used
	 *
	 * @var string
	 */
	private static $template_file = '';
	
	/**
	 * Set this to true to allow editing of the environment files via the web admin
	 *
	 * @var bool
	 */
	private static $allow_web_editing = false;
	
	private static $casting = array(
		'DeployHistory' => 'Text'
	);

	/**
	 *
	 * @var array
	 */
	public static $db = array(
		"Filename" => "Varchar(255)",
		"Name" => "Varchar",
		"URL" => "Varchar",
		"GraphiteServers" => "Text",
	);

	/**
	 *
	 * @var array
	 */
	public static $has_one = array(
		"Project" => "DNProject",
	);

	/**
	 *
	 * @var array
	 */
	public static $has_many = array(
		"DataArchives" => "DNDataArchive",
	);

	/**
	 *
	 * @var array
	 */
	public static $many_many = array(
		"Deployers"          => "Member", // Who can deploy to this environment
		"CanRestoreMembers"  => "Member", // Who can restore archive files to this environment
		"CanBackupMembers"   => "Member", // Who can backup archive files from this environment
		"ArchiveUploaders"   => "Member", // Who can upload archive files linked to this environment
		"ArchiveDownloaders" => "Member",  // Who can download archive files from this environment
		"ArchiveDeleters" => "Member"  // Who can delete archive files from this environment
	);

	/**
	 *
	 * @var array
	 */
	public static $summary_fields = array(
		"Name" => "Environment Name",
		"URL" => "URL",
		"DeployersList" => "Can Deploy List",
		"CanRestoreMembersList" => "Can Restore List",
		"CanBackupMembersList" => "Can Backup List",
		"ArchiveUploadersList" => "Can Upload List",
		"ArchiveDownloadersList" => "Can Download List",
		"ArchiveDeletersList" => "Can Delete List"
	);

	/**
	 *
	 * @var array
	 */
	public static $searchable_fields = array(
		"Name",
	);
	
	/**
	 *
	 * @var string 
	 */
	private static $default_sort = 'Name';

	/**
	 * Caches the relation to the Parent Project
	 *
	 * @var array
	 */
	protected static $relation_cache = array();

	/**
	 * 
	 * @todo this should probably be refactored so it don't interfere with the default
	 * DataObject::get() behaviour.
	 *
	 * @param string $callerClass
	 * @param string $filter
	 * @param string $sort
	 * @param string $join
	 * @param string $limit
	 * @param string $containerClass
	 * @return \DNEnvironmentList
	 */
	public static function get($callerClass = null, $filter = "", $sort = "", $join = "", $limit = null,
			$containerClass = 'DataList') {
		return new DNEnvironmentList('DNEnvironment');
	}

	/**
	 * Used by the sync task
	 *
	 * @param string $path
	 * @return \DNEnvironment
	 */
	public static function create_from_path($path) {
		$e = new DNEnvironment;
		$e->Filename = $path;
		$e->Name = basename($e->Filename, '.rb');

		// add each administrator member as a deployer of the new environment
		$adminGroup = Group::get()->filter('Code', 'administrators')->first();
		if($adminGroup && $adminGroup->exists()) {
			foreach($adminGroup->Members() as $member) {
				$e->Deployers()->add($member);
			}
		}
		return $e;
	}

	/**
	 * @return string
	 */
	public function getFullName() {
		return $this->Project()->Name . ':' . $this->Name;
	}

	/**
	 *
	 * @return DNProject
	 */
	public function Project() {
		if(!isset(self::$relation_cache['DNProject.' . $this->ProjectID])) {
			self::$relation_cache['DNProject.' . $this->ProjectID] = $this->getComponent('Project');
		}
		return self::$relation_cache['DNProject.' . $this->ProjectID];
	}

	/**
	 * Environments are only viewable by people that can view the parent project
	 *
	 * @param Member $member
	 * @return boolean
	 */
	public function canView($member = null) {
		return $this->Project()->canView($member);
	}

	/**
	 * Allow deploy only to some people.
	 *
	 * @param Member $member
	 * @return boolean
	 */
	public function canDeploy($member = null) {
		if(!$member) $member = Member::currentUser();
		if(!$member) return false; // Must be logged in to check permissions

		if(Permission::checkMember($member, 'ADMIN')) return true;

		return (bool)($this->Deployers()->byID($member->ID));
	}

	/**
	 * Allows only selected {@link Member} objects to restore {@link DNDataArchive} objects into this
	 * {@link DNEnvironment}.
	 *
	 * @param Member|null $member The {@link Member} object to test against. If null, uses Member::currentMember();
	 * @return boolean true if $member can restore, and false if they can't.
	 */
	public function canRestore($member = null) {
		if(!$member) $member = Member::currentUser();
		if(!$member) return false; // Must be logged in to check permissions

		if(Permission::checkMember($member, 'ADMIN')) return true;

		return (bool)($this->CanRestoreMembers()->byID($member->ID));
	}

	/**
	 * Allows only selected {@link Member} objects to backup this {@link DNEnvironment} to a {@link DNDataArchive}
	 * file.
	 *
	 * @param Member|null $member The {@link Member} object to test against. If null, uses Member::currentMember();
	 * @return boolean true if $member can backup, and false if they can't.
	 */
	public function canBackup($member = null) {
		$project = $this->Project();
		if($project->HasDiskQuota() && $project->HasExceededDiskQuota()) return false;

		if(!$member) $member = Member::currentUser();
		if(!$member) return false; // Must be logged in to check permissions

		if(Permission::checkMember($member, 'ADMIN')) return true;

		return (bool)($this->CanBackupMembers()->byID($member->ID));
	}

	/**
	 * Allows only selected {@link Member} objects to upload {@link DNDataArchive} objects linked to this
	 * {@link DNEnvironment}.
	 *
	 * Note: This is not uploading them to the actual environment itself (e.g. uploading to the live site) - it is the
	 * process of uploading a *.sspak file into Deploynaut for later 'restoring' to an environment. See
	 * {@link self::canRestore()}.
	 *
	 * @param Member|null $member The {@link Member} object to test against. If null, uses Member::currentMember();
	 * @return boolean true if $member can upload archives linked to this environment, false if they can't.
	 */
	public function canUploadArchive($member = null) {
		$project = $this->Project();
		if($project->HasDiskQuota() && $project->HasExceededDiskQuota()) return false;

		if(!$member) $member = Member::currentUser();
		if(!$member) return false; // Must be logged in to check permissions

		if(Permission::checkMember($member, 'ADMIN')) return true;

		return (bool)($this->ArchiveUploaders()->byID($member->ID));
	}

	/**
	 * Allows only selected {@link Member} objects to download {@link DNDataArchive} objects from this
	 * {@link DNEnvironment}.
	 *
	 * @param Member|null $member The {@link Member} object to test against. If null, uses Member::currentMember();
	 * @return boolean true if $member can download archives from this environment, false if they can't.
	 */
	public function canDownloadArchive($member = null) {
		if(!$member) $member = Member::currentUser();
		if(!$member) return false; // Must be logged in to check permissions

		if(Permission::checkMember($member, 'ADMIN')) return true;

		return (bool)($this->ArchiveDownloaders()->byID($member->ID));
	}

	/**
	 * Allows only selected {@link Member} objects to delete {@link DNDataArchive} objects from this
	 * {@link DNEnvironment}.
	 *
	 * @param Member|null $member The {@link Member} object to test against. If null, uses Member::currentMember();
	 * @return boolean true if $member can delete archives from this environment, false if they can't.
	 */
	public function canDeleteArchive($member = null) {
		if(!$member) $member = Member::currentUser();
		if(!$member) return false; // Must be logged in to check permissions

		if(Permission::checkMember($member, 'ADMIN')) return true;

		return (bool)($this->ArchiveDeleters()->byID($member->ID));
	}
	/**
	 * Get a string of people that are allowed to deploy to this environment.
	 * Used in DNRoot_project.ss to list {@link Member}s who have permission to perform this action.
	 *
	 * @return string
	 */
	public function getDeployersList() {
		return implode(", ", $this->Deployers()->column("FirstName"));
	}

	/**
	 * Get a string of people that are allowed to restore {@link DNDataArchive} objects into this environment.
	 *
	 * @return string
	 */
	public function getCanRestoreMembersList() {
		return implode(", ", $this->CanRestoreMembers()->column("FirstName"));
	}

	/**
	 * Get a string of people that are allowed to backup {@link DNDataArchive} objects from this environment.
	 *
	 * @return string
	 */
	public function getCanBackupMembersList() {
		return implode(", ", $this->CanBackupMembers()->column("FirstName"));
	}

	/**
	 * Get a string of people that are allowed to upload {@link DNDataArchive} objects linked to this environment.
	 *
	 * @return string
	 */
	public function getArchiveUploadersList() {
		return implode(", ", $this->ArchiveUploaders()->column("FirstName"));
	}

	/**
	 * Get a string of people that are allowed to download {@link DNDataArchive} objects from this environment.
	 *
	 * @return string
	 */
	public function getArchiveDownloadersList() {
		return implode(", ", $this->ArchiveDownloaders()->column("FirstName"));
	}

	/**
	 * Get a string of people that are allowed to delete {@link DNDataArchive} objects from this environment.
	 *
	 * @return string
	 */
	public function getArchiveDeletersList() {
		return implode(", ", $this->ArchiveDeleters()->column("FirstName"));
	}

	/**
	 *
	 * @return DNData
	 */
	public function DNData() {
		return Injector::inst()->get('DNData');
	}

	/**
	 * Get the current deployed build for this environment
	 *
	 * @return string
	 */
	public function CurrentBuild() {
		$history = $this->DeployHistory()->filter('Status', 'Finished');
		if(!$history->count()) {
			return false;
		}
		return $history->first();
	}

	/**
	 * A history of all builds deployed to this environment
	 *
	 * @return ArrayList
	 */
	public function DeployHistory() {
		$history = DNDeployment::get()->filter('EnvironmentID', $this->ID)->sort('LastEdited DESC');
		$repo = $this->Project()->getRepository();
		if(!$repo){
			return $history;
		}
		
		$ammendedHistory = new ArrayList();
		foreach($history as $deploy) {
			if(!$deploy->SHA) {
				continue;
			}
			try {
				$commit = $repo->getCommit($deploy->SHA);
				if($commit) {
					$deploy->Message = Convert::raw2xml($commit->getMessage());
				}
				// We can't find this SHA, so we ignore adding a commit message to the deployment
			} catch (Gitonomy\Git\Exception\ReferenceNotFoundException $ex) { }
			$ammendedHistory->push($deploy);
		}
		
		return $ammendedHistory;
	}
	
	/** 
	 * 
	 * @param string $sha
	 * @return array
	 */
	protected function getCommitData($sha) {
		try {
			$commit = new \Gitonomy\Git\Commit($this->Project()->getRepository(), $sha);
			return array(
				'AuthorName' => (string)Convert::raw2xml($commit->getAuthorName()),
				'AuthorEmail' => (string)Convert::raw2xml($commit->getAuthorEmail()),
				'Message' => (string)Convert::raw2xml($commit->getMessage()),
				'ShortHash' => Convert::raw2xml($commit->getFixedShortHash(8)),
				'Hash' => Convert::raw2xml($commit->getHash())
			);  
		} catch(\Gitonomy\Git\Exception\ReferenceNotFoundException $exc) {
			return array(
				'AuthorName' => '(unknown)',
				'AuthorEmail' => '(unknown)',
				'Message' => '(unknown)',
				'ShortHash' => $sha,
				'Hash' => '(unknown)',
			);  
		}   
	} 

	/**
	 * Does this environment have a graphite server configuration
	 *
	 * @return string
	 */
	public function HasMetrics() {
		return trim($this->GraphiteServers) != "";
	}

	/**
	 * All graphite graphs
	 *
	 * @return GraphiteList
	 */
	public function Graphs() {
		if(!$this->HasMetrics()) return null;

		$serverList = preg_split('/\s+/', trim($this->GraphiteServers));

		return new GraphiteList($serverList);
	}

	/**
	 * Graphs, grouped by server
	 * 
	 * @todo refactor out the hardcoded aa exception
	 *
	 * @return ArrayList
	 */
	public function GraphServers() {
		if(!$this->HasMetrics()) return null;

		$serverList = preg_split('/\s+/', trim($this->GraphiteServers));

		$output = new ArrayList;
		foreach($serverList as $server) {
			// Hardcoded reference to db
			if(strpos($server,'nzaadb') !== false) {
				$metricList = array("Load average", "CPU Usage", "Memory Free", "Physical Memory Used", "Swapping");
			} else {
				$metricList = array("Apache", "Load average", "CPU Usage", "Memory Free", "Physical Memory Used", "Swapping");
			}

			$output->push(new ArrayData(array(
				'Server' => $server,
				'ServerName' => substr($server,strrpos($server,'.')+1),
				'Graphs' => new GraphiteList(array($server), $metricList),
			)));
		}

		return $output;
	}

	/**
	 *
	 * @return string
	 */
	public function Link() {
		return $this->Project()->Link()."/environment/" . $this->Name;
	}

	/**
	 *
	 * @return FieldList
	 */
	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$members = array();
		foreach($this->Project()->Viewers() as $group) {
			foreach($group->Members()->map() as $k => $v) {
				$members[$k] = $v;
			}
		}
		asort($members);

		$fields->fieldByName("Root")->removeByName("Deployers");
		$fields->fieldByName("Root")->removeByName("CanRestoreMembers");
		$fields->fieldByName("Root")->removeByName("CanBackupMembers");
		$fields->fieldByName("Root")->removeByName("ArchiveUploaders");
		$fields->fieldByName("Root")->removeByName("ArchiveDownloaders");
		$fields->fieldByName("Root")->removeByName("ArchiveDeleters");

		// The Main.ProjectID
		$projectField = $fields->fieldByName('Root.Main.ProjectID')->performReadonlyTransformation();
		$fields->insertBefore($projectField, 'Name');
		
		// The Main.Name
		$nameField = $fields->fieldByName('Root.Main.Name');
		$nameField->setTitle('Environment name');
		$nameField->setDescription('A descriptive name for this environment, e.g. staging, uat, production');
		$fields->insertAfter($nameField, 'ProjectID');

		// The Main.Filename
		$fileNameField = $fields->fieldByName('Root.Main.Filename')->performReadonlyTransformation();
		$fileNameField->setTitle('Filename');
		$fileNameField->setDescription('The capistrano environment file name');
		$fields->insertAfter($fileNameField, 'Name');
		
		// The Main.Deployers
		$deployers = new CheckboxSetField("Deployers", "Who can deploy?", $members);
		$deployers->setDescription('Users who can deploy to this environment');
		$fields->insertAfter($deployers, 'URL');

		// A box to tick all snapshot boxes.
		$tickAll = new CheckboxSetField("TickAllSnapshot", "<em>All snapshot permissions</em>", $members);
		$tickAll->setDescription('UI shortcut to tick all snapshot-related boxes - not written to the database.');
		$fields->insertAfter($tickAll, 'Deployers');

		// The Main.CanRestoreMembers
		$canRestoreMembers = new CheckboxSetField('CanRestoreMembers', 'Who can restore?', $members);
		$canRestoreMembers->setDescription('Users who can restore archives from Deploynaut into this environment');
		$fields->insertAfter($canRestoreMembers, 'TickAllSnapshot');

		// The Main.CanBackupMembers
		$canBackupMembers = new CheckboxSetField('CanBackupMembers', 'Who can backup?', $members);
		$canBackupMembers->setDescription('Users who can backup archives from this environment into Deploynaut');
		$fields->insertAfter($canBackupMembers, 'CanRestoreMembers');

		// The Main.ArchiveDeleters
		$archiveDeleters = new CheckboxSetField('ArchiveDeleters', 'Who can delete?', $members);
		$archiveDeleters->setDescription(
			'Users who can delete archives from this environment\'s staging area.'
		);
		$fields->insertAfter($archiveDeleters, 'CanBackupMembers');

		// The Main.ArchiveUploaders
		$archiveUploaders = new CheckboxSetField('ArchiveUploaders', 'Who can upload?', $members);
		$archiveUploaders->setDescription(
			'Users who can upload archives linked to this environment into Deploynaut.<br>' .
			'Linking them to an environment allows limiting download permissions (see below).'
		);
		$fields->insertAfter($archiveUploaders, 'ArchiveDeleters');

		// The Main.ArchiveDownloaders
		$archiveDownloaders = new CheckboxSetField('ArchiveDownloaders', 'Who can download?', $members);
		$archiveDownloaders->setDescription(
			'Users who can download archives from this environment to their computer.<br>' .
			'Since this implies access to the snapshot, it is also a prerequisite for restores to other environments,' .
			' alongside the "Who can restore" permission.<br>' .
			'Should include all users with upload permissions, otherwise they can\'t download their own uploads.'
		);
		$fields->insertAfter($archiveDownloaders, 'ArchiveUploaders');


		// The Main.DeployConfig
		if($this->Project()->exists()) {
			$this->setDeployConfigurationFields($fields);
		}
		
		// The Main.URL field
		$urlField = $fields->fieldByName('Root.Main.URL');
		$urlField->setTitle('Server URL');
		$fields->removeByName('Root.Main.URL');
		$urlField->setDescription('This url will be used to provide the front-end with a link to this environment');
		$fields->insertAfter($urlField, 'Name');
		
		// The Extra.GraphiteServers
		$graphiteServerField = $fields->fieldByName('Root.Main.GraphiteServers');
		$fields->removeByName('Root.Main.GraphiteServers');
		$graphiteServerField->setDescription(
			'Find the relevant graphite servers at '.
			'<a href="http://graphite.silverstripe.com/" target="_blank">graphite.silverstripe.com</a>'.
			' and enter them one per line, e.g. "server.wgtn.oscar"'
		);
		$fields->addFieldToTab('Root.Extra', $graphiteServerField);
		
		Requirements::javascript('deploynaut/javascript/environment.js');
		
		// Add actions
		$action = new FormAction('check', 'Check Connection');
		$action->setUseButtonTag(true);
		$action->setAttribute('data-url', Director::absoluteBaseURL().'naut/api/'.$this->Project()->Name.'/'.$this->Name.'/ping');
		$fields->insertBefore($action, 'Name');
		return $fields;
	}
	
	/**
	 * 
	 * @param FieldList $fields
	 */
	protected function setDeployConfigurationFields(&$fields) {
		if(!$this->config()->get('allow_web_editing')) {
			return;
		}
		
		if($this->envFileExists()) {
			$deployConfig = new TextareaField('DeployConfig', 'Deploy config', $this->getEnvironmentConfig());
			$deployConfig->setRows(40);
			$fields->insertAfter($deployConfig, 'ArchiveDownloaders');
			return;
		}
			
		$noDeployConfig = new LabelField('noDeployConfig', 'Warning: This environment don\'t have deployment configuration.');
		$noDeployConfig->addExtraClass('message warning');
		$fields->insertAfter($noDeployConfig, 'Filename');
		$createConfigField = new CheckboxField('CreateEnvConfig', 'Create Config');
		$createConfigField->setDescription('Would you like to create the capistrano deploy configuration?');
		$fields->insertAfter($createConfigField, 'noDeployConfig');
	}
	
	/**
	 * 
	 */
	public function onBeforeWrite() {
		parent::onBeforeWrite();
		if($this->Name && $this->Name.'.rb' != $this->Filename) {
			$this->Filename = $this->Name.'.rb';
		}

		// Create folder if it doesn't exist
		$configDir = dirname($this->getConfigFilename());
		if(!file_exists($configDir) && $configDir) {
			mkdir($configDir, 0777, true);
		}
		
		// Create a basic new environment config from a template
		if($this->config()->get('allow_web_editing') && !$this->envFileExists() && $this->Filename && $this->CreateEnvConfig) {
			if(self::$template_file) {
				$templateFile = self::$template_file;
			} else {
				$templateFile = BASE_PATH.'/deploynaut/environment.template';
			}
			file_put_contents($this->getConfigFilename(), file_get_contents($templateFile));
		} else if($this->config()->get('allow_web_editing') && $this->envFileExists() && $this->DeployConfig) {
			file_put_contents($this->getConfigFilename(), $this->DeployConfig);
		}
	}
	
	/**
	 * Delete any related config files
	 */
	public function onAfterDelete() {
		parent::onAfterDelete();
		// Create a basic new environment config from a template
		if($this->config()->get('allow_web_editing') && $this->envFileExists()) {
			unlink($this->getConfigFilename());
		}
	}
	
	/**
	 * 
	 * @return string
	 */
	protected function getEnvironmentConfig() {
		if(!$this->envFileExists()) {
			return '';
		}
		return file_get_contents($this->getConfigFilename());
	}
	
	/**
	 * 
	 * @return boolean
	 */
	protected function envFileExists() {
		if(!$this->getConfigFilename()) {
			return false;
		}
		return file_exists($this->getConfigFilename());
	}
	
	/**
	 * 
	 * @return boolean
	 */
	public function getConfigFilename() {
		if(!$this->Project()->exists()) {
			return '';
		}
		if(!$this->Filename) {
			return '';
		}
		return $this->DNData()->getEnvironmentDir().'/'.$this->Project()->Name.'/'.$this->Filename;
	}
}
