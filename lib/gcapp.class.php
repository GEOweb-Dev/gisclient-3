<?php

class GCApp {
	static private $db;
	static private $dataDBs = array();

	public static function getDB() {
		if(empty(self::$db)) {
			$dsn = 'pgsql:dbname='.DB_NAME.';host='.DB_HOST;
			if(defined('DB_PORT')) $dsn .= ';port='.DB_PORT;
			try {
				self::$db = new PDO($dsn, DB_USER, DB_PWD);
				self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				self::$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
			} catch(Exception $e) {
				die('GCApp:Impossibile connettersi al database');
			}
		}
		return self::$db;
	}

	public static function getDataDB($path) {
		if(empty(self::$dataDBs[$path])) {
			self::$dataDBs[$path] = new GCDataDB($path);
		}
		return self::$dataDBs[$path]->db;
	}

	public static function getDataDBSchema($path) {
		if(empty(self::$dataDBs[$path])) {
			self::$dataDBs[$path] = new GCDataDB($path);
		}
		return self::$dataDBs[$path]->schema;
	}

	public static function getDataDBParams($path, $param = null) {
		if(empty(self::$dataDBs[$path])) {
			self::$dataDBs[$path] = new GCDataDB($path);
		}
		if(!empty($param)) {
			return self::$dataDBs[$path]->$param;
		} else {
			return array(
				'schema'=>self::$dataDBs[$path]->schema,
				'db_name'=>self::$dataDBs[$path]->dbName,
			);
		}
	}

    public static function getCatalogPath($catalogName) {
        $db = GCApp::getDB();

        $sql = 'select catalog_path from '.DB_SCHEMA.'.catalog where catalog_name=:catalog_name';
        $stmt = $db->prepare($sql);
        $stmt->execute(array('catalog_name'=>$catalogName));
        return $stmt->fetchColumn(0);
    }

	public static function prepareInStatement($values) {

		$i = 0;
		$params = array();
		$inArr = array();
		foreach ($values as $v) {
			$pkey = sprintf(":key%d", $i);
			$inArr[] = $pkey;
			$params[$pkey] = $v;
			$i++;
		}
		return array('inQuery' => implode(',', $inArr), 'parameters' => $params);

	}

	public static function getNewPKey($dbschema, $schema, $table, $pkey, $start=null) {

	    $db = GCApp::getDB();
	    try {
		if (is_null($start)) {
		    $sql="select $dbschema.new_pkey(:scm, :tbl, :pkey);";
		    $stmt = $db->prepare($sql);
		    $stmt->execute(array('scm' => $schema, 'tbl' => $table, 'pkey' => $pkey));
		} else {
		    $sql="select $dbschema.new_pkey(:scm, :tbl, :pkey, :start);";
		    $stmt = $db->prepare($sql);
		    $stmt->execute(array('scm' => $schema, 'tbl' => $table, 'pkey' => $pkey, 'start' => $start));
		}
	    } catch (Exception $e) {
		GCError::registerException($e);
	    }
	    print_debug($sql,null,"gcapp.class");
	    $row = $stmt->fetch(PDO::FETCH_ASSOC);
	    return isset($row['new_pkey'])?$row['new_pkey']:null;
	}


    public static function getUniqueRandomTmpFilename($dir, $prefix = '', $extension = '') {
        $letters = '1234567890qwertyuiopasdfghjklzxcvbnm';
        $lettersLength = strlen($letters) - 1;
        $filename = !empty($prefix) ? $prefix.'_' : '';
        for ($n = 0; $n < 20; $n++) {
            $filename .= $letters[rand(0, $lettersLength)];
        }
		if(!empty($extension)) $filename .= '.'.$extension;
        if (file_exists($filename))
            return self::getUniqueRandomTmpFilename($dir, $prefix, $extension);
        else
            return $filename;
    }

    public static function schemaExists($dataDb, $schema) {
        $sql = 'select schema_name from information_schema.schemata '.
            ' where schema_name = :schema ';
        $stmt = $dataDb->prepare($sql);
        $stmt->execute(array('schema'=>$schema));
        return ($stmt->rowCount() > 0);
    }

    public static function tableExists($dataDb, $schema, $tableName) {
        $sql = "select table_name from information_schema.tables ".
            " where table_schema=:schema and table_name=:table ";
        $stmt = $dataDb->prepare($sql);
        $stmt->execute(array(':schema'=>$schema, ':table'=>$tableName));
        return ($stmt->rowCount() > 0);
    }

    public static function columnExists($dataDb, $schema, $tableName, $columnName) {
        $sql = "SELECT column_name from information_schema.columns " .
                "WHERE table_schema=:schema AND table_name=:table " .
                " AND column_name = :column ";
        $stmt = $dataDb->prepare($sql);
        $stmt->execute(array(':schema'=>$schema, ':table'=>$tableName, ':column'=>$columnName));
        $result = $stmt->fetchColumn(0);
        return !empty($result);
    }

    public static function getColumns($dataDb, $schema, $tableName) {
        $sql = "SELECT column_name from information_schema.columns " .
                "WHERE table_schema=:schema AND table_name=:table " .
                " ORDER BY ordinal_position";
        $stmt = $dataDb->prepare($sql);
        $stmt->execute(array(':schema'=>$schema, ':table'=>$tableName));
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    public static function getTablePKey($db, $schema, $tableName) {
        $sql = "select column_name from
            information_schema.table_constraints c
            inner join information_schema.key_column_usage k on c.constraint_schema = k.constraint_schema and c.constraint_name = k.constraint_name
            where c.table_schema = :schema and c.table_name = :table and c.constraint_type = 'PRIMARY KEY'";
        $stmt = $db->prepare($sql);
        $stmt->execute(array('schema'=>$schema, 'table'=>$tableName));
        return $stmt->fetchColumn(0);
    }

    //questa stessa funzione è anche in admin/lib/functions.php
    //TODO: trovare dove viene usata e sostituirla con questa
	public static function nameReplace($name){

		$search = explode(","," ,ç,æ,œ,á,é,í,ó,ú,à,è,ì,ò,ù,ä,ë,ï,ö,ü,ÿ,â,ê,î,ô,û,å,e,i,ø,u,.");
		$replace = explode(",","_,c,ae,oe,a,e,i,o,u,a,e,i,o,u,a,e,i,o,u,y,a,e,i,o,u,a,e,i,o,u,_");
		if(strtoupper(CHAR_SET)=='UTF-8'){
			for($i=0;$i<count($search);$i++){
				$name=str_replace($search[$i],$replace[$i],trim($name));
}
		}
		else
			$name = str_replace($search, $replace, trim($name));

		return $name;
		//return strtolower($name);

	}
}

class GCDataDB {
	public $schema;
	public $db;
	public $dbName;

	function __construct($path) { //TODO: vedere per path diversi
	    list($dbString, $schema) = explode('/', $path);
	    if (preg_match('/dbname=([^ ]*)/', $dbString, $charMatches))
	            $dbName = $charMatches[1];
	    else
	            $dbName = $dbString;
	    if (preg_match('/host=([^ ]*)/', $dbString, $charMatches))
	            $dbHost = $charMatches[1];
	    else
	            $dbHost = DB_HOST;
	    if (preg_match('/port=([^ ]*)/', $dbString, $charMatches))
	            $dbPort = $charMatches[1];
	    else if(defined('DB_PORT'))
	            $dbPort = DB_PORT;
	    else
	            $dbPort = '5432';
	    if (preg_match('/user=([^ ]*)/', $dbString, $charMatches))
	            $dbUser = $charMatches[1];
	    else
	            $dbUser = DB_USER;
	    if (preg_match('/password=([^ ]*)/', $dbString, $charMatches))
	            $dbPasswd = $charMatches[1];
	    else
	            $dbPasswd = DB_PWD;

	    $dsn = 'pgsql:dbname=' . $dbName . ';host='.$dbHost . ';port=' . $dbPort;
	    try {
	            $this->db = new PDO($dsn, $dbUser, $dbPasswd);
	            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	    } catch(Exception $e) {
	            throw $e;
	    }
	    $this->schema = $schema;
	    $this->dbName = $dbName;
	}
}

class GCAuthor {
	// Da OL 2.13 static public $aInchesPerUnit = array(1=>39.3701,2=>12,3=>1,4=>39370.1,5=>39.370,6=>63360,7=>4374754);
	static public $aInchesPerUnit_old = array(1=>39.3701,2=>12,3=>1,4=>39370.1,5=>39.3701,6=>63360,7=>4374754);
	static public $gMapResolutions = array(156543.0339,78271.51695,39135.758475,19567.8792375,9783.93961875,4891.969809375,2445.9849046875,1222.99245234375,611.496226171875,305.7481130859375,152.87405654296876,76.43702827148438,38.21851413574219,19.109257067871095,9.554628533935547,4.777314266967774,2.388657133483887,1.1943285667419434,0.5971642833709717,0.29858214168548586,0.14929107084274293,0.07464553542137146,0.03527776,0.01763888);
	static public $defaultScaleList = array(500000000,5000000,1000000,500000,250000,100000,50000,25000,10000,5000,2000,1000,900,800,700,600,500,400,300,200,100,50);
	static public $aInchesPerUnit = array("m"=>39.3701, "ft"=>12, "inches"=>1,"km"=>39370.1, "mi"=>63360, "dd"=>4374754);

	static private $lang;
	static private $errors = array();

	public static function registerError($msg) {
		array_push(self::$errors, $msg);
	}

	public static function getErrors() {
		return self::$errors;
	}

	public static function refreshProjectMapfile($project, $publish = false) {
		require_once ADMIN_PATH."lib/functions.php";
		require_once ADMIN_PATH.'lib/spyc.php';
		require_once ADMIN_PATH.'lib/gcFeature.class.php';
		require_once ADMIN_PATH.'lib/gcMapfile.class.php';
		require_once ROOT_PATH."lib/i18n.php";

		$target = $publish ? 'public' : 'tmp';

		$mapfile = new gcMapfile();
		$mapfile->setTarget($target);
        $mapfile->writeProjectMapfile = true;
		$mapfile->writeMap("project",$project);

		$localization = new GCLocalization($project);
		$alternativeLanguages = $localization->getAlternativeLanguages();
		if($alternativeLanguages){
			foreach($alternativeLanguages as $languageId => $foo) {
				$mapfile = new gcMapfile($languageId);
				$mapfile->setTarget($target);
				$mapfile->writeMap('project', $project);
			}
		}
	}

    public static function compileProjectMapfile($project, $publicTarget = false) {
    }

	public static function refreshMapfiles($project, $publish = false) {
		require_once ADMIN_PATH."lib/functions.php";
		require_once ADMIN_PATH.'lib/spyc.php';
		require_once ADMIN_PATH.'lib/gcFeature.class.php';
		require_once ADMIN_PATH.'lib/gcMapfile.class.php';
		require_once ROOT_PATH."lib/i18n.php";

		$target = $publish ? 'public' : 'tmp';

		$mapfile = new gcMapfile();
		$mapfile->setTarget($target);
		$mapfile->writeMap("project",$project);

		$maps = GCAuthor::getMapsets($project);
        foreach($maps as $singleMap) {
          GCAuthor::generateLegendCache($project, $singleMap["mapset_name"], $target);
	  	}

		$localization = new GCLocalization($project);
		$alternativeLanguages = $localization->getAlternativeLanguages();
		if($alternativeLanguages){
			foreach($alternativeLanguages as $languageId => $foo) {
				$mapfile = new gcMapfile($languageId);
				$mapfile->setTarget($target);
				$mapfile->writeMap('project', $project);
			}
		}
	}

    public static function compileMapfiles($project, $publicTarget = false) {
      //recuperare tutti i mapset del progetto
      //mandare in esecuzione lo script per ciascuno utilizzando il metodo specifico
      $maps = GCAuthor::getMapsets($project);
      foreach($maps as $singleMap) {
        GCAuthor::compileMapfile($project, $singleMap["mapset_name"], $publicTarget);
      }
    }

	public static function refreshMapfile($project, $mapset, $publish = false) {
		require_once ADMIN_PATH."lib/functions.php";
		require_once ADMIN_PATH.'lib/spyc.php';
		require_once ADMIN_PATH.'lib/gcFeature.class.php';
		require_once ADMIN_PATH.'lib/gcMapfile.class.php';
		require_once ROOT_PATH."lib/i18n.php";

		$target = $publish ? 'public' : 'tmp';

		$mapfile = new gcMapfile();
		$mapfile->setTarget($target);
		$mapfile->writeMap("mapset",$mapset);

		GCAuthor::generateLegendCache($project, $mapset, $target);

		$localization = new GCLocalization($project);
		$alternativeLanguages = $localization->getAlternativeLanguages();
		if($alternativeLanguages){
			foreach($alternativeLanguages as $languageId => $foo) {
				$mapfile = new gcMapfile($languageId);
				$mapfile->setTarget($target);
				$mapfile->writeMap('mapset', $mapset);
			}
		}
	}

    public static function compileMapfile($project, $mapset, $layers = "", $zoomLvls = 20, $publicTarget = false) {
	  GCUtils::deleteOldFiles(GC_WEB_TMP_DIR, $project.'_'.$mapset.'_errors_*.log');
      $fileName = ROOT_PATH."map/".$project."/".($publicTarget ? "" : "tmp.").$mapset.".map";
      if(file_exists($fileName)) {
        $contents = file_get_contents($fileName);
        $pattern = preg_quote("EXTENT", '/');
        $pattern = "/$pattern.*\$/m";
        preg_match_all($pattern, $contents, $matches);
        $choordsArray = explode(" ", trim(str_replace("EXTENT", "", $matches[0][0])));
		$layerOpt = "";
		if (!empty($layers)) {
			$layerOpt = '-l "' . $layers . '"';
		}
        for($index = 0; $index<$zoomLvls; $index++) {
          $command = "shp2img -m ".$fileName." -o /tmp/test.png -all_debug 5 $layerOpt -e ".implode(" ", $choordsArray);
		  $logFile = GCApp::getUniqueRandomTmpFilename(GC_WEB_TMP_DIR, $project.'_'.$mapset.'_errors_', 'log');
		  $logFile = GC_WEB_TMP_DIR . '/' . $logFile;
          system($command.">$logFile 2>&1", $retVal);
          if($retVal !== 0) {
			  GCError::register(" Uno o più layers nel mapset $project.$mapset presentano errori;<br> cliccare <a href=" . str_replace(GC_WEB_TMP_DIR, GC_WEB_TMP_URL, $logFile) . " target=\"_blank\">qui</a> per visualizzare il log completo.");
			  break;
		  }
          $choordsArray = GCAuthor::getNextChoords($choordsArray);
		  unlink($logFile);
        }
      } else {
        GCError::register("$project.$mapset: Mapfile inesistente. Creare il file prima di procedere.");
      }
    }

	private static function generateLegendCache($project, $mapset, $target) {
		require_once ROOT_PATH."public/services/include/OwsHandler.php";
		require_once ROOT_PATH."public/services/include/wmsGetLegendGraphic.php";

		if (!defined('LEGEND_CACHE_PATH')) {
			return;
		}
		$mapsetFileName = $mapset.'.map';
		$legendDir = LEGEND_CACHE_PATH .'/'.$project.'/';
		if ($target === 'tmp') {
			$legendDir .= 'tmp.';
			$mapsetFileName .= 'tmp.';
		}
		$legendDir .= $mapset;
		if(!is_dir($legendDir)) {
            $rv = mkdir($legendDir, 0770, true);
            if ($rv === false) {
                $errorMsg = "Could not create directory $legendDir";
                GCError::register($errorMsg);
                return;
            }
        }
		array_map('unlink', glob("$legendDir/*"));
		$oMap = @ms_newMapobj(ROOT_PATH.'map/'.$project.'/'.$mapsetFileName);
		$objRequest = ms_newOwsrequestObj();
		$objRequest->addParameter('REQUEST', 'getlegendgraphic');
		$objRequest->addParameter('SERVICE', 'wms');
		$objRequest->addParameter('FORMAT', 'image/png');
		$objRequest->addParameter('WIDTH', '500');
		$objRequest->addParameter('VERSION', '1.1.1');
		$objRequest->addParameter('PROJECT', $project);
		$objRequest->addParameter('MAP', $mapset);
		$objRequest->addParameter('LAYER', $mapset);
		$paramsRequest = array(
			'WIDTH'=>'500'
		);
		if (defined('GC_SESSION_NAME') && isset($_REQUEST['GC_SESSION_ID']) && $_REQUEST['GC_SESSION_ID'] == session_id()) {

			$paramsRequest['GC_SESSION_ID'] = session_id();
		}
		$layerGroups = $oMap->getAllGroupNames();
		foreach($layerGroups as $layerGroup) {
			print_debug("Layergroup $layerGroup", null, 'legend_generate');
			$objRequest->setParameter('LAYER', $layerGroup);
			$imgPath = $legendDir . '/' . $layerGroup . '.png';
    		$result = gcGetLegendGraphic($objRequest, $oMap, $paramsRequest, $imgPath);
			if ($result === false) {
				GCError::register("Error saving legend image for layergroup  $layerGroup in $imgPath");
			}
		}
		// **** Generate legend cache images by layer ****
		$numLayers = $oMap->numlayers;
		for ($i=0; $i<$numLayers; $i++) {
			$oLayer = $oMap->getLayer($i);
			print_debug("Layer " . $oLayer->name, null, 'legend_generate');
			$objRequest->setParameter('LAYER', $oLayer->name);
			$imgPath = $legendDir . '/' . $oLayer->name . '.png';
			$result = gcGetLegendGraphic($objRequest, $oMap, $paramsRequest, $imgPath);
			if ($result === false) {
				GCError::register("Error saving legend image for layergroup  $layerGroup in $imgPath");
			}
			// **** Generate legend cache images by class ****
			for ($j=0;$j<$oLayer->numclasses; $j++) {
				try {
					$oClass = $oLayer->getClass($j);
					if($oClass->getMetaData("gc_no_image") == 1) {
						continue;
					}
					// **** Fix for error #5268 in mapserver 7: legend not diplayed if no minsize and maxsize specified
					// **** TODO: remove in next versions?
					for ($stno=0; $stno < $oClass->numstyles; $stno++) {
						$oStyle = $oClass->getStyle($stno);
						$oStyle->set('minsize', LEGEND_ICON_H);
						$oStyle->set('maxsize', LEGEND_ICON_H);
					}
					$oLegendImg = $oClass->createLegendIcon(LEGEND_ICON_W,LEGEND_ICON_H);
					$oLegendImg->saveImage($legendDir . '/' . $oLayer->name . '-' . $j . '.png');
				} catch (Exception $e) {
					$errorStr = "Error generating legend image for layer " . $oLayer->name . ", class " . $oClass->name . ". Details: " . $e->getMessage();
					$error = ms_GetErrorObj();
					while($error && $error->code != MS_NOERR)
					{
					  $errorStr .= " Error in ". $error->routine . ":" . $error->message;
					  $error = $error->next();
					}
					ms_ResetErrorList();
					GCError::register($errorStr);
				}
			}
		}
	}

    private static function getNextChoords($choordsArray) {
      $xmin = $choordsArray[0] + (($choordsArray[2] - $choordsArray[0])/4);
      $xmax = $choordsArray[2] - (($choordsArray[2] - $choordsArray[0])/4);
      $ymin = $choordsArray[1] + (($choordsArray[3] - $choordsArray[1])/4);
      $ymax = $choordsArray[3] - (($choordsArray[3] - $choordsArray[1])/4);
      return array($xmin, $ymin, $xmax, $ymax);
    }

    public static function buildFeatureQuery($aFeature, array $options = array()) {
        $defaultOptions = array(
            'include_1n_relations'=>false, //se true, le relazioni 1-n vengono incluse nella query (se, per esempio, si vuole filtrare su un campo della secondaria)
            'group_1n'=>true, //se false, vengono inclusi i campi della secondaria, di conseguenza i records non sono più raggruppati per i campi della primaria (se, per esempio, si vogliono visualizzare i dati della secondaria in tabella),
            'show_relation'=>null, //se voglio visualizzare i dati di una sola secondaria, popolo questo con il nome della relazione da visualizzare
            'getGeomAs'=>null, // se text, viene usato st_astext, altrimenti nulla (astext serve per le interrogazioni, nulla serve per il mapfile)
            'srid'=>null //se non null, viene confrontato con lo srid della feature e, se necessario, viene utilizzato st_transform()
        );
        $options = array_merge($defaultOptions, $options);


		//$aFeature = $this->aFeature;
		$layerId=$aFeature["layer_id"];
		$datalayerTable=$aFeature["data"];
		$datalayerGeom=$aFeature["data_geom"];
		$datalayerKey=$aFeature["data_unique"];
		$datalayerSRID=$aFeature["data_srid"];
		$datalayerSchema = $aFeature["table_schema"];
		$datalayerFilter = $aFeature["data_filter"];

		if(!empty($aFeature["tileindex"])) { //X TILERASTER
			$location = "'".trim($aFeature["base_path"])."' || location as location";//value for location
			$table = $aFeature["table_schema"].".".$aFeature["data"];
			$datalayerTable="(SELECT $datalayerKey as gc_objid,$datalayerGeom as the_geom,$location FROM $table) AS ". DATALAYER_ALIAS_TABLE;
			return "the_geom from ".$datalayerTable;
		}
		elseif(preg_match("|select (.+) from (.+)|i",$datalayerTable))//Definizione alias della tabella o vista pricipale (nel caso l'utente abbia definito una vista)  (da valutare se ha senso)
			$datalayerTable="($datalayerTable) AS ".DATALAYER_ALIAS_TABLE;
		else
			$datalayerTable=$datalayerSchema.".".$datalayerTable . " AS ".DATALAYER_ALIAS_TABLE;

		$joinString = $datalayerTable;

		//Elenco dei campi definiti
		if($aFeature["fields"]){
			$fieldList = array();
            $groupByFieldList = array();

			foreach($aFeature["fields"] as $idField=>$aField){

                //se non vogliamo la relazione 1-n nella query (es. WMS) oppure se non vogliamo visualizzare i dati della secondaria ma solo usarli per il filtro (es. interrogazioni su mappa), non mettiamo i campi della secondaria
                if(!empty($aField['relation']) && ($aFeature["relation"][$aField["relation"]]["relation_type"] == 2)) {
                    if(!$options['include_1n_relations'] || $options['group_1n']) continue;
                    else if(!empty($options['show_relation'])) {
                        //se voglio vedere i dati della secondaria di una sola relazione, escludo i campi delle altre
                        if($options['show_relation'] != $aFeature['relation'][$aField['relation']]['name']) continue;
                    }
                }

                //field su layer oppure su relazione 1-1
                if(empty($aField['relation'])) {
                    $aliasTable = DATALAYER_ALIAS_TABLE;
                } else {
                    $aliasTable = GCApp::nameReplace($aFeature["relation"][$aField["relation"]]["name"]);
                }

                if(!empty($aField['formula'])) {
                    if (empty($aField['relation'])){
                        $fieldName = $aField["formula"] . " AS " . $aField["field_name"];
                    }else{
                        $fieldName = str_replace($aFeature["relation"][$aField["relation"]]["name"], $aliasTable, $aField["formula"]) . " AS " . $aField["field_name"];
                    }
                    $groupByFieldList[] = $aField['field_name'];
                } else {
                    $fieldName = $aliasTable . "." . $aField["field_name"];
                    $groupByFieldList[] = $aField['field_name'];
                }

                $fieldList[] = $fieldName;
			}

			//Elenco delle relazioni
			if($aRelation=$aFeature["relation"]) {
				foreach($aRelation as $idrel => $rel){
					$relationAliasTable = GCApp::nameReplace($rel["name"]);
					//se relazione 1-n, salta se non vogliamo il join
                    //se vogliamo i dati della secondaria, elimina il groupBy
                    $joinClause = 'left join';
					if($rel["relation_type"] == 2) {
                        if(!$options['include_1n_relations']) continue;
                        if(!empty($options['show_relation']) && $rel['name'] != $options['show_relation'])
						{
							continue;
						}

						if (!empty($options['show_relation']) && $rel['name'] == $options['show_relation']){
							$joinClause = 'join';
						}

                        if(!$options['group_1n']) {
                            $groupByFieldList = null;
                        }
					}


                    $joinList = array();
                    foreach($rel['join_field'] as $joinField) {
                        $joinList[] = DATALAYER_ALIAS_TABLE . '.' . $joinField[0] . ' = ' . $relationAliasTable . '.' . $joinField[1];
                    }

                    $joinFields = implode(" AND ",$joinList);
                    $joinString = "$joinString $joinClause ".$rel["table_schema"].".". $rel["table_name"] ." AS ". $relationAliasTable ." ON (".$joinFields.")";
				}

			}

			//$fieldString = implode(",",$fieldList);
		}

        $geomField = DATALAYER_ALIAS_TABLE.'.'.$datalayerGeom;
        if($options['srid'] && $options['srid'] != 'EPSG:'.$aFeature['data_srid']) {
            $srid = (int)str_replace('EPSG:', '', $options['srid']);
            $geomField = 'st_transform('.$geomField.', '.$srid.')';
        }
        if($options['getGeomAs']) {
            if($options['getGeomAs'] == 'text') {
                $geomField = 'st_astext('.$geomField.')';
            }
        }
		$datalayerTable = 'SELECT '.DATALAYER_ALIAS_TABLE.'.'.$datalayerKey.' as gc_objid';
		if(!empty($datalayerGeom)) $datalayerTable .= ', ' . $geomField.' as gc_geom';
		if(!empty($fieldList)) $datalayerTable .= ', '.implode(',', $fieldList);
        $datalayerTable = 'SELECT * FROM (' . $datalayerTable . ' FROM '.$joinString . ') as LAYER_Q';
		if (!empty($datalayerFilter)) $datalayerTable .= ' WHERE ' . $datalayerFilter;
        if(!empty($groupByFieldList)) $datalayerTable .= ' GROUP BY gc_objid, gc_geom, ' . implode(', ', $groupByFieldList);
		print_debug($datalayerTable,null,'datalayer');
		return $datalayerTable;

    }

	public static function GCTypeFromDbType($dbType) {
		$typesMap = array(
			1=>array('varchar','text','char','bool','bpchar'),
			2=>array('int','int2','int4','int8','float','float4','float8', 'serial2', 'serial4', 'serial8', 'decimal', 'numeric'),
			3=>array('date', 'timetz' ,'timestamp','timestamptz')
		);
		foreach($typesMap as $typeId => $types) {
			if(in_array($dbType, $types)) return $typeId;
		}
		return false;
	}

	public static function getLang() {
		if(empty(self::$lang)) {
			if(isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
				$langs=explode(',',$_SERVER['HTTP_ACCEPT_LANGUAGE']);
			} else {
				$langs=array('it', 'en', 'de');
			}

			if(!empty($_SESSION['AUTHOR_LANGUAGE'])) {
				self::$lang = $_SESSION['AUTHOR_LANGUAGE'];
			} else if(defined('FORCE_LANGUAGE')) {
				self::$lang = FORCE_LANGUAGE;
			} else {
				self::$lang = (!empty($_REQUEST["language"])) ? $_REQUEST["language"] : substr($langs[0],0,2);
			}
			$_SESSION['AUHTOR_LANGUAGE'] = self::$lang;
		}
		return self::$lang;
	}

	public static function getTabDir() {
		$lang = self::getLang();
		$rel_dir="config/tab/$lang/";
		if(!is_dir(ROOT_PATH.$rel_dir)) $rel_dir="config/tab/it/";
		if(defined('TAB_DIR')) $rel_dir="config/tab/".TAB_DIR."/";
		return $rel_dir;
	}

	public static function getHintsFileName() {
      $lang = self::getLang();
      $rel_dir="config/hints/$lang/";
      if(!is_dir(ROOT_PATH.$rel_dir)) $rel_dir="config/hints/it/";
      return $rel_dir."hints.txt";
    }

	public static function getMapsets($project) {
		$db = GCApp::getDB();

		$sql = "select mapset_name, mapset_title, template, project_name from ".DB_SCHEMA.".mapset where project_name=? order by mapset_order, mapset_title";
		$stmt = $db->prepare($sql);
		$stmt->execute(array($project));
		$mapsets = $stmt->fetchAll(PDO::FETCH_ASSOC);
		foreach($mapsets as &$mapset) {
			$url = defined('MAP_URL') ? MAP_URL : '';
			if(!empty($mapset['template'])) $url .= $mapset['template'];
			$url .= (strpos($url, '?') === false ? '?' : '&') . 'mapset='.$mapset['mapset_name'];
			$mapset['url'] = $url;
		}
		unset($mapset);

		return $mapsets;
	}

	public static function getMapsetLayergroups($mapset) {
		$db = GCApp::getDB();

		$sql = "select layergroup_name from ".DB_SCHEMA.".mapset
				inner join ".DB_SCHEMA.".mapset_layergroup using(mapset_name)
				inner join ".DB_SCHEMA.".layergroup using(layergroup_id)
				where owstype_id in (1,2,3,4)
				and mapset_name=?";
		$stmt = $db->prepare($sql);
		$stmt->execute(array($mapset));
		$layergroups= $stmt->fetchAll(PDO::FETCH_COLUMN, 'layergroup_name');
		return $layergroups;
	}

	public static function getTowsFeatures($project) {
		$db = GCApp::getDB();

		$sql = "select project_name, theme_title, layergroup_title, layer_title, layergroup_name || '.' || layer_name as feature_type from ".DB_SCHEMA.".layer ".
			" inner join ".DB_SCHEMA.".layergroup using(layergroup_id) ".
			" inner join ".DB_SCHEMA.".theme using(theme_id) ".
			" inner join ".DB_SCHEMA.".field using(layer_id) ".
			" where layer.queryable=1 and field.editable=1 and theme.project_name=? ".
			" group by project_name, theme_title, layergroup_title, layer_title, layer_id, feature_type ".
			" order by theme_title, layergroup_title, layer_title ";
		$stmt = $db->prepare($sql);
		$stmt->execute(array($project));
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public static function t($key) {
		return self::translate($key);
	}

	public static function translate($key) {
		$lang = self::getLang();
		if (isset(self::$translations[$key])) {
			if (isset(self::$translations[$key][$lang])) {
				// found localization
				return self::$translations[$key][$lang];
			} else {
				// requested localization not found,
				// take first localized string
				$availableLangs = array_keys(self::$translations[$key]);
				return self::$translations[$key][$availableLangs[0]];
			}
		} else {
			// worst case, return key itself
			return $key;
		}
	}

	static private $translations = array(
		'yes' => array('it'=>'Si','de'=>'Ja'),
		'no' => array('it'=>'No', 'de'=>'Nein'),
		'button_edit' => array('it'=>'Modifica', 'de'=>'Ändern'),
		'button_new' => array('it'=>'Nuovo', 'de'=>'Neu'),
		'button_back' => array('it'=>'Indietro', 'de'=>'Zurück'),
		'button_save' => array('it'=>'Salva', 'de'=>'Speichern'),
		'button_cancel' => array('it'=>'Annulla', 'de'=>'Abbrechen'),
		'button_publish' => array('it'=>'Pubblica', 'de'=>'Herausgeben'),
		'button_delete' => array('it'=>'Elimina', 'de'=>'Löschen'),
		'button_export' => array('it'=>'Esporta', 'de'=>'Exportieren'),
		'button_options' => array('it'=>'Opzioni', 'en'=>'Options'),
		'close' => array('it'=>'Chiudi', 'de'=>'Schließen'),
		'title'=>array('it'=>'Titolo', 'de'=>'Titel'),
		'name'=>array('it'=>'Nome', 'de'=>'Name'),
		'description'=>array('it'=>'Descrizione', 'de'=>'Beschreibung'),
		'nodata' => array('it'=>'Nessun Dato Presente', 'de'=>'Keine Daten'),
		'undefined'=>array('it'=>'Non definito', 'de'=>'Nicht definiert'),
		'table'=>array('it'=>'Tabella', 'de'=>'Tabelle'),
		'column'=>array('it'=>'Colonna', 'de'=>'Spalte'),
		'field'=>array('it'=>'Campo', 'de'=>'Feld'),
		'group'=>array('it'=>'Gruppo', 'de'=>'Gruppe'),
		'pkey'=>array('it'=>'Campo chiave', 'de'=>'Primärschlüssel'),
		'format'=>array('it'=>'Formato', 'de'=>'Format'),
		'image'=>array('it'=>'Immagine', 'de'=>'Bild'),
		'symbol'=>array('it'=>'Simbolo', 'de'=>'Symbol'),
		'category'=>array('it'=>'Categoria', 'de'=>'Art'),
		'position'=>array('it'=>'Posizione', 'de'=>'Position'),
		'save_to_temp'=>array('it'=>'Salva in un mapfile temporaneo', 'de'=>'In einem temporären Mapfile abspeichern'),
		'auto_refresh_mapfiles'=>array('it'=>'Rigenera automaticamente i mapfiles', 'de'=>'Mapset automatisch aktualisieren'),
		'save'=>array('it'=>'Salva', 'de'=>'Speichern'),
		'all'=>array('it'=>'Tutte', 'en'=>'All', 'de'=>'Alles'),
		'online_maps'=>array('it'=>'Mappe online', 'de'=>'Online-Karten'),
		'ogc_services'=>array('it'=>'Servizi OGC', 'de'=>'OGC Dienste'),
		'update'=>array('it'=>'Aggiorna', 'de'=>'Aktualisieren'),
		'compile'=>array('it'=>'Verifica', 'de'=>''),
		'temporary'=>array('it'=>'temporanee', 'en'=>'temporary', 'de'=>'temporär'),
		'public'=>array('it'=>'pubblici', 'de'=>'öffentlich'),
		'theme'=>array('it'=>'Tema', 'de'=>'Thema'),
		'layergroup'=>array('it'=>'Gruppo Layer', 'de'=>'Layergruppe'),
		'layer'=>array('it'=>'Layer', 'de'=>'Layer'),
		'lookup_id'=>array('it'=>'Campo chiave lookup', 'de'=>'Schlüsselfeld in der Nachschlagetabelle'),
		'lookup_name'=>array('it'=>'Campo descrizione lookup', 'de'=>'Beschreibungsfeld in der Nachschlagetabelle'),
		'confirm_delete'=>array('it'=>'Sei sicuro di voler eliminare il record?', 'de'=>'Sind Sie sicher, dass sie diesen Eintrag löschen wollen?'),
		'translations'=>array('it'=>'Traduzioni', 'de'=>'Übersetzungen'),
		'List of available Maps'=>array('it'=>'Elenco delle mappe disponibili', 'de'=>'Verfügbare Karten'),
		'Username'=>array('it'=>'Nome Utente', 'de'=>'Benutzername'),
		'Password'=>array('it'=>'Password', 'de'=>'Kennwort'),
		'project'=>array('it'=>'Progetto', 'de'=>'Projekt'),
		'symbology'=>array('it'=>'Simbologia', 'de'=>'Symbole'),
		'progress'=>array('it'=>'Operazione in corso'),
		'failed_login'=>array('it'=>'Utente e/o password specificati non validi.'),
		'class'=>array('it'=>'Classe', 'en'=>'Class'),
		'style'=>array('it'=>'Stile', 'en'=>'Style'),
		'layer_groups'=>array('it'=>'Gruppi utenti layer', 'en'=>'Layer groups'),
		'relation'=>array('it'=>'Relazioni', 'en'=>'Relation'),
		'qt_link'=>array('it'=>'Link modello report', 'en'=>'QT Link'),
		'qt'=>array('it'=>'Modello report', 'en'=>'QT'),
		'qt_field'=>array('it'=>'Campo report', 'en'=>'QT Field'),
		'qt_relation'=>array('it'=>'Relazione report', 'en'=>'QT Relation'),
		'field_groups'=>array('it'=>'Autorizzazioni sul campo', 'en'=>'Field Groups'),
		'mapset_list'=>array('it'=>'Lista mappe', 'en'=>'Mapset'),
		'resetFieldMsg'=>array('it'=>'Modificare il valore comporta la reinizializzazione dei campi del layer. Sicuro di procedere?', 'en'=>'Any change on catalog will reinit layer fields. Are you sure about proceeding?'),
		'check_fields'=>array('it'=>'Controllo Campi', 'en'=>'Check Fields'),
	);
}

class GCError {
	static private $errors = array();

	public static function register($msg) {
		array_push(self::$errors, $msg);
	}

	public static function get() {
		return self::$errors;
	}

	public static function registerException($e) {
	    array_push(self::$errors, $e->getMessage());
	}
}

class GCLog {
	static private $logFiles = array();
	static private $logDir = GC_WEB_TMP_DIR;
	static private $logLevel = 0;

	public static function log($message, $level=3, $withdate=true, $newline=true) {
		if(defined('GC_LOG_FILES')) self::$logFiles = GC_LOG_FILES;
		if(defined('GC_LOG_DIRECTORY')) self::$logDir = GC_LOG_DIRECTORY;
		if(defined('GC_LOG_LEVEL')) self::$logLevel = GC_LOG_LEVEL;

		if ($level > self::$logLevel || count(self::$logFiles) == 0) {
			return;
		}
		$logFile = self::$logDir;
		if(!is_dir($logFile)) {
			mkdir($logFile);
		}
		$key = array_search(__FUNCTION__, array_column(debug_backtrace(), 'function'));
		$calledFrom = debug_backtrace()[$key]['file'];
		$calledFrom = basename($calledFrom, '.php');
		if (isset(self::$logFiles[$calledFrom])) {
			$logFile .= '/' . self::$logFiles[$calledFrom];
		}
		else if (isset(self::$logFiles['default'])) {
			$logFile .= '/' . self::$logFiles['default'];
		}
		else {
			print_debug("No log file defined: $logFile", NULL, 'log');
			return;
		}
		$msg = '';
		if ($withdate) {
			$msg .= date('Y-m-d H:i:s O') . ' ';
		}
		$msg .= $message;
		if ($newline) {
			$msg .= PHP_EOL;
		}
		if (!error_log($msg, 3, $logFile)) {
			print_debug("Error writing log file: $logFile", NULL, 'log');
		}
	}
}

class GCUtils {
	public static function parseBox($box) {
		$split = explode(',', str_replace(array('BOX(',')'), '', $box));
		list($l, $b) = explode(' ', $split[0]);
		list($r, $t) = explode(' ', $split[1]);
		return array($l, $b, $r, $t);
	}

    public static function deleteOldFiles($path, $pattern = '*') {
        $files = glob($path.'/'.$pattern);
		foreach($files as $file) {
			$isold = (time() - filectime($file)) > 5 * 60 * 60;
			if (is_file($file) && $isold) {
				if (false === unlink($file)) {
					echo __FILE__.":".__LINE__." Could not remove $file";
				}
			}
		}
    }
}
