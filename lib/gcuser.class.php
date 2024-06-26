<?php

abstract class AbstractUser {
    protected $options;
    protected $username;
    protected $groups;
    protected $adminUsername = SUPER_USER;
    protected $isProjAdmin = array();
    protected $authorizedLayers = array();
    protected $mapLayers = array();

    function __construct(array $options = array()) {
        $defaultOptions = array();
        $this->options = array_merge($defaultOptions, $options);

        if(!self::sessionStarted()) {
            if(defined('GC_SESSION_NAME')) session_name(GC_SESSION_NAME);
            session_start();
        }

        if(!empty($_SESSION['USERNAME'])) {
            $this->username = $_SESSION['USERNAME'];
            if(!empty($_SESSION['GROUPS'])) {
                $this->groups = $_SESSION['GROUPS'];
            }
            else {
                $this->_getUserGroups();
                $_SESSION['GROUPS'] = $this->groups;
            }
        }
    }

    public function isAuthenticated() {
        return !empty($this->username);
    }

    public function isAdmin($project = null) {
        //$project serve a vedere se è admin del progetto
        if(!$project) {
            return ($this->username == $this->adminUsername);
        } else {
            // **** The global Admin should be administrator for every project
            if ($this->username == $this->adminUsername) {
                GCLog::log("Access granted to project " .$project . " for user " . $this->username . " as global administrator");
                return true;
            }
            if (isset($this->isProjAdmin[$project])) {
                    return $this->isProjAdmin[$project];
            }
            $db = GCApp::getDB();
            $sql = 'select username from '.DB_SCHEMA.'.project_admin
                where project_name = :project and username = :username';
            $stmt = $db->prepare($sql);
            $stmt->execute(array(
                'username'=>$this->username,
                'project'=>$project
            ));
            $result = $stmt->fetchColumn(0);
            if (!empty($result)) {
                GCLog::log("Access granted to project " .$project . " for user " . $this->username . " as project administrator");
                $this->isProjAdmin[$project] = true;
                return true;
            }
        }
        $this->isProjAdmin[$project] = false;
        return false;
    }

    public function login($username, $password) {
        $db = GCApp::getDB();

        $sql = 'select username from '.DB_SCHEMA.'.users where username=:user and enc_pwd=:pass';
        $stmt = $db->prepare($sql);
        $stmt->execute(array(
            'user'=>$username,
            'pass'=>$password
        ));
        $usernameInDb = $stmt->fetchColumn(0);
        if(empty($usernameInDb)) {
			return false;
		}
        $this->username = $usernameInDb;
        $this->_setSessionData();
        return true;
    }

    public function logout() {
		session_destroy();
		unset($_SESSION);
        if(defined('GC_SESSION_NAME')) session_name(GC_SESSION_NAME);
        $this->username = null;
		session_start();
    }

    public function getUsername() {
        return $this->username;
    }

    public function getGroups() {
        return $this->groups;
    }

    protected function _setSessionData() {
        $_SESSION['USERNAME'] = $this->username;
        GCLog::log("Session opened for user ".$this->username);
        $this->_getUserGroups();
        $_SESSION['GROUPS'] = $this->groups;
        $gcGroups = empty($this->groups) ? 'none' : implode(',', $this->groups);
        GCLog::log("Group membership for user ".$this->username . " : " . $gcGroups, 4);
    }

    protected function _getUserGroups() {
        $groups = $this->getUserGroups($this->username);
        $this->groups = empty($groups) ? array() : $groups;
    }

	public function setAuthorizedLayers(array $filter) {
		$db = GCApp::getDB();
        $gcUser = empty($this->username) ? 'anonymous' : $this->username;
        $projectName = null;
		if(isset($filter['mapset_name'])) {
			$sqlFilter = 'mapset_name = :mapset_name';
			$sqlValues = array(':mapset_name'=>$filter['mapset_name']);
            $sql = 'select project_name from '.DB_SCHEMA.'.mapset where mapset_name=:mapset_name';
            $_SESSION['GISCLIENT_USER_LAYER']['GISCLIENT_USER_FILTERS']['mapset_name'][$filter['mapset_name']] = true;
            GCLog::log("Access to mapset " . $filter['mapset_name'] . " for user ".$gcUser);
		} else if(isset($filter['theme_name'])) {
			$sqlFilter = 'theme_name = :theme_name';
			$sqlValues = array(':theme_name'=>$filter['theme_name']);
            $sql = 'select project_name from '.DB_SCHEMA.'.theme where theme_name=:theme_name';
            $_SESSION['GISCLIENT_USER_LAYER']['GISCLIENT_USER_FILTERS']['theme_name'][$filter['theme_name']] = true;
            GCLog::log("Access to theme " . $filter['theme_name'] . " for user ".$gcUser);
		} else if(isset($filter['project_name'])) {
			$projectName = $filter['project_name'];
            $_SESSION['GISCLIENT_USER_LAYER']['GISCLIENT_USER_FILTERS']['project_name'][$filter['project_name']] = true;
            GCLog::log("Access to project " . $filter['project_name'] . " for user ".$gcUser);
		} else {
			return false;
		}

        if ($projectName === null) {
            $stmt = $db->prepare($sql);
            $stmt->execute($sqlValues);
            $projectName = $stmt->fetchColumn(0);
        }

        $groupFilter = '';
		if (empty($filter['show_as_public'])) {
			$isAdmin = ($this->isAdmin() || $this->isAdmin($projectName));
		} else {
			$isAdmin = false;
		}
        if(!$isAdmin) {
            if(!empty($this->groups)) {
                $in = array();
                foreach($this->groups as $k => $groupId) {
                    array_push($in, ':group_param_'.$k);
                    $sqlValues[':group_param_'.$k] = $groupId;
                }
                $groupFilter = ' and groupname in ('.implode(',',$in).') ';
            } else {
                $groupFilter = ' and 1=2 ';
            }
        }

		if (empty($filter['show_as_public'])) {
			$authClause = '(layer.private=1 '.$groupFilter.' ) OR (coalesce(layer.private,0)=0)';
		} else {
			$authClause = '(coalesce(layer.private,0)=0)';
		}

        $sql = 'SELECT DISTINCT project_name, theme_name, layergroup_name, layergroup_single, layer.layer_id, layer.private, layer.layer_name, layergroup.layergroup_title, layer.layer_title, layer.maxscale, layer.minscale,layer.hidden,layer.layer_order,
            case when coalesce(layer.private,1) = 1 then '.($isAdmin ? '1' : 'max(coalesce(wms,0))').' else 1 end as wms,
            case when coalesce(layer.private,1) = 1 then '.($isAdmin ? '1' : 'max(coalesce(wfs,0))').' else 1 end as wfs,
            case when coalesce(layer.private,1) = 1 then '.($isAdmin ? '1' : 'max(coalesce(wfst,0))').' else 1 end as wfst,
            layer_order
            FROM '.DB_SCHEMA.'.theme
            INNER JOIN '.DB_SCHEMA.'.layergroup USING (theme_id)
            INNER JOIN '.DB_SCHEMA.'.mapset_layergroup using (layergroup_id)
            LEFT JOIN '.DB_SCHEMA.'.layer USING (layergroup_id)
            LEFT JOIN '.DB_SCHEMA.'.layer_groups USING (layer_id)
            WHERE ('.$sqlFilter.') AND ('.$authClause.') GROUP BY project_name, theme_name, layergroup_name, layergroup_single, layer.layer_id, layer.private, layer.layer_name, layergroup.layergroup_title, layer.layer_title, layer.maxscale, layer.minscale,layer.hidden,layer.layer_order ORDER BY layer.layer_order;';

        $stmt = $db->prepare($sql);
        $stmt->execute($sqlValues);

		while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

			$featureType = $row['layergroup_name'].".".$row['layer_name'];
			$_SESSION['GISCLIENT_USER_LAYER'][$row['project_name']][$featureType] = array('WMS'=>$row['wms'],'WFS'=>$row['wfs'],'WFST'=>$row['wfst']);

			if(!empty($row['layer_id'])) {
				// se il filtro è richiesto e non è settato in sessione, escludi il layer
				if(isset($requiredAuthFilters[$row['layer_id']])) {
					$filterName = $requiredAuthFilters[$row['layer_id']];
					if(!isset($_SESSION['GISCLIENT']['AUTHFILTERS'][$filterName])) continue;
				}
				$this->authorizedLayers[] = $row['layer_id'];
			}
			// create arrays if not exists
			if(!isset($this->mapLayers[$row['theme_name']])) $this->mapLayers[$row['theme_name']] = array();
			if(!isset($this->mapLayers[$row['theme_name']][$row['layergroup_name']])) $this->mapLayers[$row['theme_name']][$row['layergroup_name']] = array();
			if($row['layergroup_single']==1) {
                if (count($this->mapLayers[$row['theme_name']][$row['layergroup_name']]) === 0) {
                    $this->mapLayers[$row['theme_name']][$row['layergroup_name']] = array("name" => $row['layergroup_name'], "title" => $row['layergroup_title'], "grouptitle" => $row['layergroup_title']);
                    if ($row['minscale']) {
                        $this->mapLayers[$row['theme_name']][$row['layergroup_name']]['minScale'] = floatval($row['minscale']);
                    }
                    if ($row['maxscale']) {
                        $this->mapLayers[$row['theme_name']][$row['layergroup_name']]['maxScale'] = floatval($row['maxscale']);
                    }
                }
                else {
                    if ($row['minscale']) {
                        if (isset($this->mapLayers[$row['theme_name']][$row['layergroup_name']]['minScale'])) {
                            $this->mapLayers[$row['theme_name']][$row['layergroup_name']]['minScale'] = min(floatval($row['minscale']), $this->mapLayers[$row['theme_name']][$row['layergroup_name']]['minScale']);
                        }
                    }
                    else {
                        unset($this->mapLayers[$row['theme_name']][$row['layergroup_name']]['minScale']);
                    }
                    if ($row['maxscale']) {
                        if (isset($this->mapLayers[$row['theme_name']][$row['layergroup_name']]['maxScale'])) {
                            $this->mapLayers[$row['theme_name']][$row['layergroup_name']]['maxScale'] = max(floatval($row['maxscale']), $this->mapLayers[$row['theme_name']][$row['layergroup_name']]['maxScale']);
                        }
                    }
                    else {
                        unset($this->mapLayers[$row['theme_name']][$row['layergroup_name']]['maxScale']);
                    }
                }
            }
            else {
                array_push($this->mapLayers[$row['theme_name']][$row['layergroup_name']], array("name" => $featureType, "title" => $row['layer_title']?$row['layer_title']:$row['layer_name'], "grouptitle" => $row['layergroup_title'], "minScale" => $row['minscale'], "maxScale" => $row['maxscale'], "hidden" => $row['hidden']));
            }
		};
	}

    public function isAuthorized(array $filter) {
        if (isset($_SESSION['GISCLIENT_USER_LAYER']['GISCLIENT_AUTH_FILTERS'][array_key_first($filter)][$filter[array_key_first($filter)]])) {
            return $_SESSION['GISCLIENT_USER_LAYER']['GISCLIENT_AUTH_FILTERS'][array_key_first($filter)][$filter[array_key_first($filter)]];
        }
        $db = GCApp::getDB();
        $gcUser = empty($this->username) ? 'anonymous' : $this->username;
        $projectName = null;
		if(isset($filter['mapset_name'])) {
			$sqlFilter = 'mapset_name = :mapset_name';
			$sqlValues = array(':mapset_name'=>$filter['mapset_name']);
            $sql = 'select project_name from '.DB_SCHEMA.'.mapset where mapset_name=:mapset_name';
		} else if(isset($filter['theme_name'])) {
			$sqlFilter = 'theme_name = :theme_name';
			$sqlValues = array(':theme_name'=>$filter['theme_name']);
            $sql = 'select project_name from '.DB_SCHEMA.'.theme where theme_name=:theme_name';
		} else if(isset($filter['project_name'])) {
			$projectName = $filter['project_name'];
		} else {
			return false;
		}

        if ($projectName === null) {
            $stmt = $db->prepare($sql);
            $stmt->execute($sqlValues);
            $projectName = $stmt->fetchColumn(0);
        }

        $groupFilter = '';
		if (empty($filter['show_as_public'])) {
			$isAdmin = ($this->isAdmin() || $this->isAdmin($projectName));
		} else {
			$isAdmin = false;
		}
        if(!$isAdmin) {
            if(!empty($this->groups)) {
                $in = array();
                foreach($this->groups as $k => $groupId) {
                    array_push($in, ':group_param_'.$k);
                    $sqlValues[':group_param_'.$k] = $groupId;
                }
                $groupFilter = ' and groupname in ('.implode(',',$in).') ';
            } else {
                $groupFilter = ' and 1=2 ';
            }
        }

		if (empty($filter['show_as_public'])) {
			$authClause = '(layer.private=1 '.$groupFilter.' ) OR (coalesce(layer.private,0)=0)';
		} else {
			$authClause = '(coalesce(layer.private,0)=0)';
		}

        $sql = 'SELECT COUNT(*) AS n_layers
            FROM '.DB_SCHEMA.'.theme
            INNER JOIN '.DB_SCHEMA.'.layergroup USING (theme_id)
            INNER JOIN '.DB_SCHEMA.'.mapset_layergroup using (layergroup_id)
            LEFT JOIN '.DB_SCHEMA.'.layer USING (layergroup_id)
            LEFT JOIN '.DB_SCHEMA.'.layer_groups USING (layer_id)
            WHERE ('.$sqlFilter.') AND ('.$authClause.') GROUP BY project_name, theme_name, layergroup_name, layergroup_single, layer.layer_id, layer.private, layer.layer_name, layergroup.layergroup_title, layer.layer_title, layer.maxscale, layer.minscale,layer.hidden,layer.layer_order ORDER BY layer.layer_order;';

        $stmt = $db->prepare($sql);
        $stmt->execute($sqlValues);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if($row['n_layers'] > 0) {
            $_SESSION['GISCLIENT_USER_LAYER']['GISCLIENT_AUTH_FILTERS'][array_key_first($filter)][$filter[array_key_first($filter)]] = true;
            return true;
        }
        else {
            $_SESSION['GISCLIENT_USER_LAYER']['GISCLIENT_AUTH_FILTERS'][array_key_first($filter)][$filter[array_key_first($filter)]] = false;
            return false;
        }
    }

	public function getAuthorizedLayers(array $filter) { //TODO: controllare chi la usa
		if(empty($this->mapLayers)) $this->setAuthorizedLayers($filter);
		return $this->authorizedLayers;
	}

	public function getMapLayers(array $filter) { //TODO: controllare chi la usa
		if(empty($this->mapLayers)) $this->setAuthorizedLayers($filter);
		return $this->mapLayers;
	}

    public function authGCService(array $filter, $strict = false) {
        $isAuthenticated = !empty($this->username);
		// user does not have an open session, try to log in
		if (!$isAuthenticated &&
			isset($_SERVER['PHP_AUTH_USER']) &&
			isset($_SERVER['PHP_AUTH_PW'])) {
			if ($this->login($_SERVER['PHP_AUTH_USER'], self::encPwd($_SERVER['PHP_AUTH_PW']))) {
				$this->setAuthorizedLayers($filter);
				$isAuthenticated = true;
                return true;
			}
		}
		// user could not even log in, send correct headers and exit
		if (!$isAuthenticated) {
            $key = array_search(__FUNCTION__, array_column(debug_backtrace(), 'function'));
		    $calledFrom = debug_backtrace()[$key]['file'];
            print_debug("unauthorized access in $calledFrom", null, 'system'); // ***** Use Log facility???
            if ($strict) {
                header('WWW-Authenticate: Basic realm="Gisclient"');
			    header('HTTP/1.0 401 Unauthorized');
            }
		}
        else {
                if (!isset($_SESSION['GISCLIENT_USER_LAYER']['GISCLIENT_USER_FILTERS'][array_key_first($filter)][$filter[array_key_first($filter)]])) {
        			$this->setAuthorizedLayers($filter);
            }
        }
        return $isAuthenticated;
    }

    public function getClientConfiguration() {
      if($this->isAdmin()) {
        $result = array("CLIENT_ID" => $this->username);
        if(defined("SUPER_USER_CLIENT_COMPONENTS"))
          $result["CLIENT_COMPONENTS"] = explode(",",  SUPER_USER_CLIENT_COMPONENTS);
        return $result;
      } else if(!empty($this->groups)){
        $db = GCApp::getDB();
        /* Create a string for the parameter placeholders filled to the number of params */
        $place_holders = implode(',', array_fill(0, count($this->groups), '?'));
        $sql = "select key, value from ".DB_SCHEMA.".group_properties where groupname in($place_holders)";
        $stmt = $db->prepare($sql);
		$stmt->execute($this->groups);
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
          if(!empty($row['value'])) {
            $arrValue = explode(",",$row['value']);
            $result[$row['key']] = array_unique(array_merge(array_key_exists($row['key'], $result) ? $result[$row['key']] : array(), $arrValue));
          }
        }
        $result["CLIENT_ID"] = $this->username;
        return $result;
      }
      return array("CLIENT_ID" => $this->isAuthenticated() ? $this->username : "-anonymous_".session_id()."-");
    }

	public function saveUserOption($key, $value) {
		$db = GCApp::getDB();
		$sql = 'delete from '.DB_SCHEMA.'.users_options where option_key=:key and username=:username';
		$stmt = $db->prepare($sql);
		$stmt->execute(array('key'=>$key, 'username'=>$this->username));

		$sql = 'insert into '.DB_SCHEMA.'.users_options (username, option_key, option_value) '.
			' values (:username, :key, :value)';
		$stmt = $db->prepare($sql);
		$stmt->execute(array('username'=>$this->username, 'key'=>$key, 'value'=>$value));
	}

	public function setUserOptions() {
		$db = GCApp::getDB();
		$sql = 'select option_key, option_value from '.DB_SCHEMA.'.users_options where username=?';
		$stmt = $db->prepare($sql);
		$stmt->execute(array($this->username));
		while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$_SESSION[$row['option_key']] = $row['option_value'];
		}
	}

    public static function getUsers() {
        $db = GCApp::getDB();

        $sql = 'select username, cognome, nome from '.DB_SCHEMA.'.users';
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getUserData($username) {
        $db = GCApp::getDB();

        $sql = 'select username, cognome, nome from '.DB_SCHEMA.'.users where username=:user';
        $stmt = $db->prepare($sql);
        $stmt->execute(array('user'=>$username));
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function getAllGroups() {
        $db = GCApp::getDB();

        $sql = 'select groupname, description from '.DB_SCHEMA.'.groups';
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getUserGroups($username) {
        $db = GCApp::getDB();

        $sql = 'select groupname from '.DB_SCHEMA.'.user_group where username=:user';
        $stmt = $db->prepare($sql);
        $stmt->execute(array('user'=>$username));
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    public static function getGroupData($groupname) {
        $db = GCApp::getDB();

        $sql = 'select groupname, description from '.DB_SCHEMA.'.groups where groupname=:group';
        $stmt = $db->prepare($sql);
        $stmt->execute(array('group'=>$groupname));
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function encPwd($pwd) {
        return md5($pwd);
    }

    public static function sessionStarted()
    {
        if ( php_sapi_name() !== 'cli' ) {
            if ( version_compare(phpversion(), '5.4.0', '>=') ) {
                return session_status() === PHP_SESSION_ACTIVE ? TRUE : FALSE;
            } else {
                return session_id() === '' ? FALSE : TRUE;
            }
        }
        return FALSE;
    }
}
