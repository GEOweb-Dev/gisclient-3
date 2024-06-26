<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of gcReport
 *
 * @author geosim2
 */
class gcReport {
    var $db;
    var $authorizedLayers = array();
    var $authorizedGroups = array();
    var $selgroupList = array();
    var $mapLayers = array();
    var $reportDefs = array();
    var $defaultLayers = array();
    var $projectName;
    var $mapsetName;
    var $mapsetSingleLayer;
    var $reportConfig;
    var $reportQueryResult;
    var $templates;
    var $totRowsReport = 0;
    var $request;
    var $mapsetSRID;
    var $mapsetGRID;
    var $mapsetUM = "m";
    var $mapResolutions = array();
    var $mapsetResolutions = array();
    var $scaleListResolutions = array();
    var $levelOffset = 0;
    var $tilesExtent;
    var $activeBaseLayer = '';
    var $isPublicLayerQueryable = true; //FLAG CHE SETTA I LAYER PUBBLICI ANCHE INTERROGABILI
    var $fractionalZoom = 0;
    var $allOverlays = 0;
    var $coordSep = ' ';
    var $listProviders = array(); //Elenco dei provider settati per il mapset
    var $projDefs = array();
    var $getLegend = false;
    var $result = '';
    var $error = '';
    var $materialize = false;

    private $i18n;
    protected $oMap;
    protected $sldContents = array();

    function __construct ($mapsetName, $languageId = null){

        $this->db = GCApp::getDB();
        $reportConfig=array();

        $sql = "SELECT mapset.*, ".
            "project.project_name,project.project_title " .
            "FROM ".DB_SCHEMA.".mapset INNER JOIN ".DB_SCHEMA.".project USING (project_name) ".
            "WHERE mapset_name=?;";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array($mapsetName));

        if($stmt->rowCount() == 0){
            $reportConfig['result'] = 'error';
            $reportConfig['error'] = "Il mapset $mapsetName non esiste";
            $this->reportConfig = $reportConfig;
            //echo "Il mapset \"{$mapsetName}\" non esiste<br /><br />\n\n";
            //echo "{$stmt->queryString}<br />\n";
            //echo "{$sql}<br />\n";
            return;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if(!empty($languageId)) {
            $this->i18n = new GCi18n($row['project_name'], $languageId);
            $row['mapset_title'] = $this->i18n->translate($row['mapset_title'], 'mapset', $row['mapset_name'], 'mapset_title');
            $row['project_title'] = $this->i18n->translate($row['project_title'], 'project', $row['project_name'], 'project_title');
        }

        $this->projectName = $row["project_name"];
        $this->mapsetName = $row["mapset_name"];

        //$this->_getLayers();

        //$this->_getReports();

        $reportConfig["reportDefs"] = array();

        //SE HO DEFINITO UN CONTESTO AGGIUNGO LE OPZIONI DI CONTESTO (PER ORA AGGIUNGO I LAYER DEL REDLINE) (TODO FRANCESCO)
        //SOVRASCRIVO GLI ATTRIBUTI DI reportConfig E AGGIUNGO I LAYER DEL CONTEXT
        //LASCEREI IL DOPPIO PASSAGGIO JSONENCODE JSONDECODE PER IL CONTROLLO DEGLI ERRORI ..... DA VEDERE

        if(!empty($_REQUEST['context'])) {
            $userContext = $this->_getUserContext($_REQUEST['context']);
            if(!empty($userContext) && !empty($userContext['layers']))
                $reportConfig["context_layers"] = $userContext['layers'];
        }

        $user = new GCUser();
        if($user->isAuthenticated()) {
            $reportConfig['logged_username'] = $user->getUsername();
        }


        $reportConfig['result'] = 'ok';

        //$this->maxRes = $maxRes;
        //$this->minRes = $minRes;
        $this->reportConfig = $reportConfig;

    }

    function __destruct (){
        unset($this->db);
    }


    function queryReport($request) {

        if (!isset($request['report_id']))
        {
            $this->reportQueryResult["result"] = 'error';
            $this->reportQueryResult['error'] = "Nessun report ID specificato";
            return;
        }

        $this->_initQuery($request);
        if ($this->reportQueryResult["result"] === 'error') {
            return;
        }
        $this->reportQueryResult['templates'] = $this->templates;
        $this->reportQueryResult['data'] = $this->_getInfoByTemplate($this->templates[$request['report_id']]);
        $this->reportQueryResult['total'] = $this->totRowsReport;
        $this->reportQueryResult['result'] = $this->result;
    }

    function exportReport($request) {
        if (!isset($request['report_id']))
        {
            $this->reportQueryResult["result"] = 'error';
            $this->reportQueryResult['error'] = "Nessun report ID specificato";
            return;
        }

        if ($request['action'] != 'xls' && $request['action'] != 'pdf')
        {
            $this->reportQueryResult["result"] = 'error';
            $this->reportQueryResult['error'] = "Formato per esportazione non riconosciuto";
            return;
        }

        $this->_initQuery($request);
        if ($this->reportQueryResult["result"] === 'error') {
            return;
        }
        $this->reportQueryResult["export_format"] = $request['action'];
        $this->reportQueryResult["feature_type"] = $this->templates[$request['report_id']]["report_name"];
        $this->reportQueryResult["fields"] = array_values($this->templates[$request['report_id']]["field"]);
        $this->reportQueryResult['data'] = $this->_getInfoByTemplate($this->templates[$request['report_id']]);

        $this->reportQueryResult['result'] = $this->result;
    }

    function materializeReport($request) {
        if (!isset($request['report_id']))
        {
            $this->reportQueryResult["result"] = 'error';
            $this->reportQueryResult['error'] = "Nessun report ID specificato";
            return;
        }
        $this->materialize = true;
        $this->_initQuery($request);
        if ($this->reportQueryResult["result"] === 'error') {
            return;
        }
        $this->materialize = false;
        $aTemplate = $this->templates[$request['report_id']];
        $datalayerSchema = GCApp::getDataDBSchema($aTemplate['catalog_path']);
        $tableName = 'gw_qt_' . $request['report_id'];
        $aTemplate['table_schema'] = $datalayerSchema;
        $aTemplate['fields'] = $aTemplate['field']; //temporaneo

        $connStr = $aTemplate['catalog_path'];
        if (preg_match('/user=([^ ]*)/', $connStr, $charMatches))
	            $connStr = str_replace('user='.$charMatches[1], 'user='.DB_USER, $connStr);
	    if (preg_match('/password=([^ ]*)/', $connStr, $charMatches))
                $connStr = str_replace('password='.$charMatches[1], 'password='.DB_PWD, $connStr);
        $dataDB = new GCDataDB($connStr);

        $options = array('getGeomAs'=>'text');
        if(!empty($this->request['srid'])) $options['srid'] = $this->request['srid'];

        print_debug("Materialize report " . $request['report_id'] . " - starting",null,'report');

        $queryString = $this->_buildReportQuery($aTemplate, $options);

        $sqlCheck = "SELECT TO_CHAR(GREATEST(last_analyze, last_autoanalyze),'YYYY-MM-DD HH24:MI:SS.US') AS mod_time FROM pg_stat_all_tables WHERE schemaname=:schemaname AND relname=:relname";
        $stmt = $dataDB->db->prepare($sqlCheck);
        $stmt->execute(array('schemaname'=>$datalayerSchema, 'relname'=>$tableName));
        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($res) == 1) {
            if (empty($res[0]['mod_time'])) {
                print_debug("Other instance running for materialized report " . $request['report_id'] . " - exiting",null,'report');
                return;
            }
            $viewTime = $res[0]['mod_time'];
            $stmt->execute(array('schemaname'=>$datalayerSchema, 'relname'=>$aTemplate['data']));
            $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($res) != 1) {
                // **** Table not found in pg_stat_all tables... maybe this is a report based on a view?
                $sqlCheck = "SELECT TO_CHAR(MAX(GREATEST(last_analyze, last_autoanalyze)),'YYYY-MM-DD HH24:MI:SS.US') AS mod_time
                             FROM pg_stat_all_tables
                             WHERE schemaname=:schemaname AND relname IN
                                (SELECT table_name
                                 FROM information_schema.view_table_usage
                                 WHERE table_schema=:schemaname and view_name=:relname)";
                $stmt = $dataDB->db->prepare($sqlCheck);
                $stmt->execute(array('schemaname'=>$datalayerSchema, 'relname'=>$aTemplate['data']));
                $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($res) != 1) {
                    print_debug("Error - origin data layer table not found for report " . $request['report_id'] . " - exiting",null,'report');
                    return;
                }
            }
            $dataTime = $res[0]['mod_time'];
            echo $viewTime . '-' . $dataTime;
            if ($dataTime < $viewTime) {
                print_debug("Materialized report " . $request['report_id'] . " up to date ($dataTime < $viewTime) - exiting",null,'report');
                return;
            }
        }
        print_debug("Materialize report " . $request['report_id'] . " - execution started",null,'report');

        echo 'CREATE MATERIALIZED VIEW ' . $datalayerSchema . '.' . $tableName . ' AS ' . $queryString . ' WITH DATA';
        $dataDB->db->query('DROP MATERIALIZED VIEW IF EXISTS ' . $datalayerSchema . '.' . $tableName);
        $dataDB->db->query('CREATE MATERIALIZED VIEW ' . $datalayerSchema . '.' . $tableName . ' AS ' . $queryString . ' WITH NO DATA');
        $dataDB->db->query('GRANT SELECT ON ' . $datalayerSchema . '.' . $tableName . ' TO ' . MAP_USER);
        $dataDB->db->query('REFRESH MATERIALIZED VIEW ' . $datalayerSchema . '.' . $tableName);
        $dataDB->db->query('ANALYZE '  . $datalayerSchema . '.' . $tableName);

        print_debug("Materialize report " . $request['report_id'] . " - completed",null,'report');
    }



    function _initQuery($request) {

        $dbschema=DB_SCHEMA;
        $user = new GCUser();
        $userGroupFilter = "";
        if((!$user->isAdmin($this->projectName)) && $this->materialize == false) {
            $this->authorizedGroups = $user->getGroups();
            $userGroup = '';
            if(!empty($this->authorizedGroups)) $userGroup =  " OR groupname in('".implode("','", $this->authorizedGroups)."')";
            $userGroupFilter = ' (groupname IS NULL '.$userGroup.') AND ';
        }

        $sqlField="select qt_field.*, sql_function, qt_relation.qt_relation_name, qt_relation.qt_relation_id, qt_relation.qtrelationtype_id, e_qt_relationtype.qtrelationtype_name, qt_relation.data_field_1, qt_relation.data_field_2, qt_relation.data_field_3, qt_relation.table_field_1, qt_relation.table_field_2, qt_relation.table_field_3, qt_relation.table_name, catalog_path, catalog_url
        from $dbschema.qt_field
        left join $dbschema.qt_relation using (qt_relation_id)
        left join $dbschema.e_qt_relationtype using (qtrelationtype_id)
        left join $dbschema.catalog using (catalog_id)
        left join $dbschema.e_fieldtype using (fieldtype_id)
        left join $dbschema.qt_field_groups using(qt_field_id)
        where $userGroupFilter qt_field.qt_id = :qt_id
        order by qtfield_order,field_header;";

        $stmt = $this->db->prepare($sqlField);
        print_debug($sqlField,null,'report');
        $stmt->execute(array('qt_id'=>$request['report_id']));
		$qRelation = array();
		$qField = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$Id=$row["qt_id"];
			$fieldId=$row["qt_field_id"];
			$qField[$Id][$fieldId]["field_name"]=trim($row["qt_field_name"]);
			$qField[$Id][$fieldId]["field_alias"]=trim($row["field_header"]);
            $qField[$Id][$fieldId]["title"]=trim($row["field_header"]);
			$qField[$Id][$fieldId]["formula"]=trim($row["formula"]);
			$qField[$Id][$fieldId]["field_type"]=$row["fieldtype_id"];
            $qField[$Id][$fieldId]["aggr_function"]=$row["sql_function"];
			$qField[$Id][$fieldId]["data_type"]=$row["datatype_id"];
			$qField[$Id][$fieldId]["order_by"]=$row["orderby_id"];
			$qField[$Id][$fieldId]["field_format"]=$row["field_format"];
			$qField[$Id][$fieldId]["search_type"]=trim($row["searchtype_id"]);
			$qField[$Id][$fieldId]["result_type"]=trim($row["resultype_id"]);
			$qField[$Id][$fieldId]["field_filter"]=trim($row["field_filter"]);
			$qField[$Id][$fieldId]["search_function"]=(isset($row["search_function"]))?trim($row["search_function"]):'';
			$qField[$Id][$fieldId]["relation"]=$row["qt_relation_id"];
			$qField[$Id][$fieldId]["column_width"]=$row["column_width"];
			$f=array();

                        if(!empty($row['qt_relation_id'])) {
                                $relationId = $row['qt_relation_id'];
				if(($row["data_field_1"])&&($row["table_field_1"])) $f[]=array(trim($row["data_field_1"]),trim($row["table_field_1"]));
				if(($row["data_field_2"])&&($row["table_field_2"])) $f[]=array(trim($row["data_field_2"]),trim($row["table_field_2"]));
				if(($row["data_field_3"])&&($row["table_field_3"])) $f[]=array(trim($row["data_field_3"]),trim($row["table_field_3"]));
				$qRelation[$Id][$relationId]["join_field"]=$f;
				$qRelation[$Id][$relationId]["name"]=trim($row["qt_relation_name"]);
				$qRelation[$Id][$relationId]["table_name"]=trim($row["table_name"]);
				$qRelation[$Id][$relationId]["path"]=trim($row["catalog_path"]);
				$qRelation[$Id][$relationId]["catalog_url"]=trim($row["catalog_url"]);
				if($row["qtrelationtype_id"]==100){
					$row["qtrelationtype_id"]=2;
					$this->isGraph=1;
				}
				$qRelation[$Id][$relationId]["relation_type"]=$row["qtrelationtype_id"];
                $qRelation[$Id][$relationId]["relation_join_type"]=$row["qtrelationtype_name"];
			}
		}
/*         echo 'Fields<br><pre>';
		var_export($qField); */
		//Assegno alle relazioni i valori  di spontechema e connessione
		foreach($qRelation as $qt=>$aRel){
			foreach($aRel as $qtrel=>$row){
				$aConnInfo = connInfofromPath($row["path"]);
				$qRelation[$qt][$qtrel]["table_connection"] = $aConnInfo[0];
				$qRelation[$qt][$qtrel]["table_schema"] = $aConnInfo[1];
			}
		}
/*         echo 'Relations<br><pre>';
		var_export($qRelation); */
		//Aggiungo eventuali hyperlink relativi ai query_template


		//query template *******************
		//$sqlTemplate="select layer.layer_id,layer_name,layer.layergroup_id,layergroup.hidden,mapset_filter,id,base_url,catalog_path,catalog_url,connection_type,data,data_geom,data_filter,data_unique,data_srid,template,tolerance,name,max_rows,selection_color,zoom_buffer,edit_url,groupobject,layertype_ms,static,papersize_id,filter,papersize_size,papersize_orientation from $dbschema.qt inner join $dbschema.layer using (layer_id) inner join $dbschema.e_layertype using (layertype_id) inner join $dbschema.catalog using (catalog_id) inner join $dbschema.layergroup using (layergroup_id) inner join $dbschema.project using (project_name) left join $dbschema.e_papersize using(papersize_id)  where qt.id $sqlQt order by order;";
        $sqlTemplate="select qt.qt_id, qt.qt_name as report_name, qt.qt_filter as data_filter, qt.materialize, layer.layer_id, layer.layer_name, layergroup_name || '.' || layer_name as type_name, layer.private, layer.data, layer.data_geom, layer.data_filter as layer_data_filter, catalog_path, catalog_url, connection_type, data_unique, data_srid
        from $dbschema.qt
        inner join $dbschema.layer using (layer_id)
        inner join $dbschema.catalog using (catalog_id)
        inner join $dbschema.layergroup using (layergroup_id)
        where qt_id = :qt_id order by qt_order;";
		print_debug($sqlTemplate,null,'report');

        $stmt = $this->db->prepare($sqlTemplate);
        $stmt->execute(array('qt_id'=>$request['report_id']));
		//Tutti i query template dei modelli di ricerca interessati
		$allTemplates = array();
		while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
            $typeName = $row["type_name"];
            if($row['private'] != 0 && @$_SESSION['GISCLIENT_USER_LAYER'][$this->projectName][$typeName]['WFS'] != 1 && $this->materialize == false) {
                $this->reportQueryResult["result"] = 'error';
                $this->reportQueryResult['error'] = "Diritti insufficenti per la visualizzazione del report (id = " . $request['report_id'] . ")";
                continue;
            }
			$reportId=$row["qt_id"];
			$allTemplates[$reportId]=$row;
			$allTemplates[$reportId]["field"]= (isset($qField[$reportId]))?$qField[$reportId]:null;
			$allTemplates[$reportId]["relation"]= (isset($qRelation[$reportId]))?$qRelation[$reportId]:null;
			//$allTemplates[$reportId]["link"]=(isset($qLink[$layerId]))?array_values($qLink[$layerId]):array();
		}
		//nel caso di query point devo settare il valore della query per ogni qt che contiene la definizione del buffer
        //FD: l'eventuale geometria da aggiungere alla where arriverà direttamente dal client
		//if($_REQUEST["spatialQuery"] != QUERY_POINT) $this->_setQueryGeom();

		//Memorizzo il valore per verificare se devo comunque ripulire la mappa dalla selezione corrente.
        //FD: tutte queste cose non servono più
		//$this->mapToUpdate = isset($_SESSION[$myMap]["RESULT"])?1:0;
		//$this->zoomToResult = 0;
		//Svuoto la sessione con i risultati della query precedente
		//unset($_SESSION[$myMap]["RESULT"]);

		//ritorno le informazioni per ogni querytemplate

		//Se resultAction non prevede l'aggiornameto della mappa devo verificare l'esistenza del poligono di selezione, nel caso lo tolgo e aggiorno la mappa
		//if(isset($_SESSION[$myMap]["SELECTION_ACTIVE"]) && $_REQUEST["resultAction"]==0){
			//unset($_SESSION[$myMap]["SELECTION_ACTIVE"]);
			//$this->mapToUpdate=1;
		//}


/* 		echo 'AllTemplates<br><pre>';
        var_export($allTemplates); */

        $this->templates = $allTemplates;
        $this->request = $request;
    }

    function _getInfoByTemplate($aTemplate){
		//$myMap = "MAPSET_".$_REQUEST["mapset"];
		//$templateId = $aTemplate["layer_id"];

        $dataDB = GCApp::getDataDB($aTemplate['catalog_path']);
        $datalayerSchema = GCApp::getDataDBSchema($aTemplate['catalog_path']);
        $aTemplate['table_schema'] = $datalayerSchema;
        $aTemplate['fields'] = $aTemplate['field']; //temporaneo
        $aTemplate['order_by'] = !empty($this->request['order_by'])?$this->request['order_by']:NULL;

        $options = array('getGeomAs'=>'text');
        if(!empty($this->request['srid'])) $options['srid'] = $this->request['srid'];

        if (!empty($aTemplate['materialize'])) {
            $tableNameMat = 'gw_qt_' . $aTemplate['qt_id'];
            $sqlCheck = 'SELECT GREATEST(last_analyze, last_autoanalyze) as mod_time FROM pg_stat_all_tables WHERE schemaname=:schemaname AND relname=:relname';
            $stmt = $dataDB->prepare($sqlCheck);
            $stmt->execute(array('schemaname'=>$datalayerSchema, 'relname'=>$tableNameMat));
            $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (isset($res[0])) {
                $queryString = 'SELECT * FROM ' . $datalayerSchema . '.' . $tableNameMat;
            }
            else {
                $queryString = $this->_buildReportQuery($aTemplate, $options);
            }
        }
        else {
            $queryString = $this->_buildReportQuery($aTemplate, $options);
        }

        $params = array();
        $whereClause = null;
        $limitClause = null;

        if(!empty($this->request['query'])) {
            $whereClause = $this->request['query'];
            if(!empty($this->request['values'])) {
                if (is_array($this->request['values'])) {
                    $params = $this->request['values'];
                }
                else {
                    $params = json_decode($this->request['values'], true);
                    if (!$params === null)
                        $whereClause = null;
                }
            }
        } else if(!empty($this->request['action']) && $this->request['action'] == 'viewdetails') {
            $whereClause = $aTemplate['data_unique'].' = :'.$aTemplate['data_unique'];
            $params[$aTemplate['data_unique']] = $this->request['featureId'];
        }


        if(!empty($this->request['rows'])) {
            $rowsNum = intval($this->request['rows']);
            $limitClause = " LIMIT " .  $rowsNum;
            if (!empty($this->request['page'])) {
                $offsetNum = intval($this->request['page']);
                $offsetNum = ($offsetNum)*$rowsNum;
                $limitClause .= " OFFSET " . $offsetNum;
            }
            $queryStringCount = 'select count(*) as tot from ('.$queryString.') as foo';
            if(!empty($whereClause))
                $queryStringCount .=  ' where '.$whereClause;

            $stmt = $dataDB->prepare($queryStringCount);
            $stmt->execute($params);
            $totRec = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->totRowsReport = $totRec['tot'];
        }


        if(!empty($whereClause)) {
            $queryString = 'select * from ('.$queryString.') as foo where '.$whereClause;
        }

        if(!empty($orderClause)) {
            $queryString .= ' ORDER BY ' . $orderClause;
        }

        if(!empty($limitClause)) {
            $queryString .= $limitClause;
        }

        //die($queryString);
        $stmt = $dataDB->prepare($queryString);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->result = 'ok';
        return $results;
    }


    function _buildReportQuery($aFeature, array $options = array()) {
        $defaultOptions = array(
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
            $orderByFieldList = array();

			foreach($aFeature["fields"] as $idField=>$aField){

                if(empty($aField['relation'])) {
                    $aliasTable = DATALAYER_ALIAS_TABLE;
                } else {
                    $aliasTable = GCApp::nameReplace($aFeature["relation"][$aField["relation"]]["name"]);
                }

                if(!empty($aField['formula'])) {
                    if (empty($aField['relation'])){
                        $fieldName = $aField["formula"];
                    }else{
                        $fieldName = str_replace($aFeature["relation"][$aField["relation"]]["name"], $aliasTable, $aField["formula"]);
                    }

                    if (!empty($aField['aggr_function'])) {
                        $fieldName = str_replace('__field__', $fieldName, $aField['aggr_function']) . " AS " . $aField["field_name"];
                    }
                    else {
                        $fieldName .= " AS " . $aField["field_name"];
                        $groupByFieldList[] = $aField['field_name'];
                        if ($aField['order_by'] == 1) {
                            $orderByFieldList[] = $aField['field_name'] . ' ASC';
                        }
                        else if ($aField['order_by'] == 2) {
                            $orderByFieldList[] = $aField['field_name'] . ' DESC';
                        }
                    }

                } else {
                    $fieldName = $aliasTable . "." . $aField["field_name"];
                    if (!empty($aField['aggr_function'])) {
                        $fieldName = str_replace('__field__', $fieldName, $aField['aggr_function']) . " AS " . $aField["field_name"];
                    }
                    else {
                        $groupByFieldList[] = $aliasTable.'.'.$aField['field_name'];
                        if ($aField['order_by'] == 1) {
                            $orderByFieldList[] = $aliasTable.'.'.$aField['field_name'] . ' ASC';
                        }
                        else if ($aField['order_by'] == 2) {
                            $orderByFieldList[] = $aliasTable.'.'.$aField['field_name'] . ' DESC';
                        }
                    }
                }
                $fieldList[] = $fieldName;
			}

			//Elenco delle relazioni
			if($aRelation=$aFeature["relation"]) {
				foreach($aRelation as $idrel => $rel){
					$relationAliasTable = GCApp::nameReplace($rel["name"]);

                    $joinList = array();
                    foreach($rel['join_field'] as $joinField) {
                        $joinList[] = DATALAYER_ALIAS_TABLE . '.' . $joinField[0] . ' = ' . $relationAliasTable . '.' . $joinField[1];
                    }

                    $joinFields = implode(" AND ",$joinList);
                    $joinString = "$joinString " . $rel['relation_join_type'] . " " .$rel["table_schema"].".". $rel["table_name"] ." AS ". $relationAliasTable ." ON (".$joinFields.")";
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

        $whereClause = null;
        if(isset($aFeature['data_filter'])) {
            $whereClause = '('.$aFeature['data_filter'].')';
        }
        if (isset($aFeature['layer_data_filter'])) {
            if (isset($whereClause)) {
                $whereClause .= ' AND ('.$aFeature['layer_data_filter'].')';
            }
            else {
                $whereClause = '('.$aFeature['layer_data_filter'].')';
            }
        }

        if (!empty($aFeature['order_by'])) {
            $orderByFieldList = array($aFeature['order_by']);
        }
        //$orderByFieldList = !empty($aFeature['order_by'])?array()$aFeature['order_by']]:implode(', ', $orderByFieldList);
		//$datalayerTable = 'SELECT '.DATALAYER_ALIAS_TABLE.'.'.$datalayerKey.' as gc_objid, '.$geomField.' as gc_geom';
        //if(!empty($fieldList)) $datalayerTable .= ', '.implode(',', $fieldList);
        // **** Add check if fieldList empty
        $datalayerTable = 'SELECT ' . implode(',', $fieldList) . ' FROM '. $joinString;
        if(!empty($whereClause)) $datalayerTable .= ' WHERE '. $whereClause;
        if(!empty($groupByFieldList) && count($groupByFieldList) < count($fieldList)) $datalayerTable .= ' GROUP BY '. implode(', ', $groupByFieldList);
        if(!empty($orderByFieldList)) $datalayerTable .= ' ORDER BY ' . implode(', ', $orderByFieldList);
		print_debug($datalayerTable,null,'report');
		return $datalayerTable;

    }

    function displayReports() {
        $this->_getReports();

        $this->reportConfig["reportDefs"] = $this->reportDefs;
        $this->reportConfig["result"] = 'ok';
    }


    function _getReports(){

        //Restituisce le features e i range di scala
        $userGroupFilter = '';
        $user = new GCUser();
        if(!$user->isAdmin($this->projectName)) {
            $this->authorizedGroups = $user->getGroups();
            $userGroup = '';
            if(!empty($this->authorizedGroups)) $userGroup =  " OR groupname in('".implode("','", $this->authorizedGroups)."')";
            $userGroupFilter = ' (groupname IS NULL '.$userGroup.') AND ';
        }

        $sql = "SELECT theme.project_name, theme_name, theme_title, theme_single, theme.theme_id, layergroup_id, layergroup_name, layergroup_name || '.' || layer_name as type_name, layer.layer_id, layer.searchable_id, qt.qt_id, coalesce(qt_title,qt_name) as report_title, data_unique, layer.data, catalog.catalog_id, catalog.catalog_url, private, layertype_id, classitem, labelitem, maxvectfeatures, qt.materialize, qt.selection_color, selection_width, qt_field_id, qt_field_name, qt_field.filter_field_name as filter_field_name, qt_field.field_header as field_header, qt_field.fieldtype_id as fieldtype_id, qt_field.field_format as field_format, qt_field.column_width as column_width, qt_relation_name, qtrelationtype_id, qt_field.searchtype_id as searchtype_id, qt_field.resultype_id as resultype_id, qt_field.datatype_id as datatype_id, qt_field.field_filter as field_filter, layer.hidden, qt_field.editable as field_editable, layer.data_type as data_type, qt_field.lookup_table as lookup_table, qt_field.lookup_id, qt_field.lookup_name,qt_relation.qt_relation_id, qt_relation.data_field_1, qt_relation.table_field_1
				FROM " . DB_SCHEMA . ".theme
				INNER JOIN " . DB_SCHEMA . ".layergroup using (theme_id)
				INNER JOIN " . DB_SCHEMA . ".mapset_layergroup using (layergroup_id)
				INNER JOIN " . DB_SCHEMA . ".layer using (layergroup_id)
				INNER JOIN " . DB_SCHEMA . ".catalog using (catalog_id)
                INNER JOIN " . DB_SCHEMA . ".qt using (layer_id)
				LEFT JOIN " . DB_SCHEMA . ".qt_field using(qt_id)
				LEFT JOIN " . DB_SCHEMA . ".qt_relation using(qt_relation_id)
                LEFT JOIN " . DB_SCHEMA . ".qt_field_groups using(qt_field_id)
                WHERE  $userGroupFilter layer.queryable = 1 AND  mapset_layergroup.mapset_name=:mapset_name ";
        $sql .= " ORDER BY theme_title, theme_id, qt_order, qt_name, qtfield_order, qt_field.field_header;";
        $stmt = $this->db->prepare($sql);

        $stmt->execute(array($this->mapsetName));
        $reportDefs = array();

        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if(!empty($this->i18n)) {
                $row = $this->i18n->translateRow($row, 'layer', $row['layer_id'], array('layer_title','classitem','labelitem'));
                $row = $this->i18n->translateRow($row, 'field', $row['field_id'], array('field_name','field_header'));
            }

            //DETTAGLIO AUTORIZZAZIONI
            $reportID = $row["qt_id"];
            $typeName = $row["type_name"];

            if($row['private'] == 0) {
                if(!$this->isPublicLayerQueryable)
                    continue;
            } else {
                if($_SESSION['GISCLIENT_USER_LAYER'][$row['project_name']][$typeName]['WFS'] != 1)
                    continue;
            }

            $reportTitle = $row["report_title"];
            $groupTitle = empty($row["theme_title"]) ? $row["theme_name"] : $row["theme_title"];
            $index = 'theme_' . $row['theme_id'];
            if (!isset($reportDefs[$index]))
                $reportDefs[$index] = array();
            if(!isset($reportDefs[$index][$reportID]))
                $reportDefs[$index][$reportID] = array();

            $reportDefs[$index][$reportID]["reportID"] = $reportID;
            $reportDefs[$index][$reportID]["title"] = $reportTitle;
            $reportDefs[$index][$reportID]["group"] = $groupTitle;
            $reportDefs[$index][$reportID]["featureType"] = $typeName;

            if ($row['field_editable'] == 1 && !isset($reportDefs[$index][$reportID]['towsFeatureType'])) {
                $reportDefs[$index][$reportID]['towsFeatureType'] = $row['data'];
            }
            if (!empty($row["catalog_url"]))
                $reportDefs[$index][$reportID]["docurl"] = $row["catalog_url"];
            if (!empty($row["classitem"]))
                $reportDefs[$index][$reportID]["classitem"] = $row["classitem"];
            if (!empty($row["labelitem"]))
                $reportDefs[$index][$reportID]["labelitem"] = $row["labelitem"];
            /*
            if (!empty($row["data_type"]))
                $reportDefs[$index][$reportID]["data_type"] = $row["data_type"];
            if (!empty($row["geometryType"]))
                $reportDefs[$index][$reportID]["data_type"] = $row["data_type"];
             */
            if (!empty($row["maxvectfeatures"]))
                $reportDefs[$index][$reportID]["maxvectfeatures"] = intval($row["maxvectfeatures"]);
            if (!empty($row["materialize"]))
                $reportDefs[$index][$reportID]["materializeReport"] = intval($row["materialize"]);
            $reportDefs[$index][$reportID]['hidden'] = intval($row['hidden']);
            //$reportDefs[$index][$reportID]['searchable'] = intval($row['searchable_id']);
            //if (isset($featureTypesLinks[$row['layer_id']])) {
            //    $featureTypes[$index][$typeName]['link'] = $featureTypesLinks[$row['layer_id']];
            //}

            $userCanEdit = false;
            //if(@$_SESSION['GISCLIENT_USER_LAYER'][$row['project_name']][$typeName]['WFST'] == 1 || $user->isAdmin($this->projectName))
            //    $userCanEdit = true;
            /*
            if (!empty($row["selection_color"]) && !empty($row["selection_width"])) {
                $color = "RGB(" . str_replace(" ", ",", $row["selection_color"]) . ")";
                $size = intval($row["selection_width"]);
                if ($row["layertype_id"] == 1)
                    $featureTypes[$index][$typeName]["symbolizer"] = array("Point" => array("fillColor" => "$color", "pointRadius" => $size));
                if ($row["layertype_id"] == 2 || $row["layertype_id"] == 3)
                    $featureTypes[$index][$typeName]["symbolizer"] = array("Line" => array("strokeColor" => "$color", "strokeWidth" => $size));
            }
            */

            //TODO DA VERIFICARE DA VEDERE ANCHE L'OPZIONE PER IL CAMPO EDITABILE CHE SOVRASCRIVE QUELLO DI DEFAULT
            //if(($fieldName = $row["field_name"]) && (empty($row["field_group"]) || in_array($row["field_group"],$this->authorizedGroups) || $user->isAdmin($this->projectName))){//FORSE NON SERVONO TUTTI GLI ATTRIBUTI!!!
                /*
                if(!empty($row["relation_name"])){
                    $fieldName = $row["relation_name"] . "_" . NameReplace($row["field_header"]);
                }
                */
                //AGGIUNGO IL CAMPO GEOMETRIA COME PRIMO CAMPO
             if($fieldName = $row["qt_field_name"]) {
                if(empty($reportDefs[$index][$reportID]["properties"])) $reportDefs[$index][$reportID]["properties"] = array();

                /*
                if($row['data_unique'] == $fieldName) {
                    $isPrimaryKey = 1;
                } else {
                    $isPrimaryKey = 0;
                }
                */
                $aRel=array();
                if($row["qt_relation_name"]){
                    $aRel["relationName"] =  $row["qt_relation_name"];
                    $aRel["relationType"] = intval($row["qtrelationtype_id"]);
                    $aRel["relationTitle"] =  isset($row["qtrelation_title"])?$row["qtrelation_title"]:$row["qt_relation_name"];
                    if(!isset($reportDefs[$index][$reportID]["relations"]))
                        $reportDefs[$index][$reportID]["relations"] = array();
                }
                if($aRel && (!in_array($aRel, $reportDefs[$index][$reportID]["relations"])))
                    $reportDefs[$index][$reportID]["relations"][] = $aRel;

                $fieldSpecs = array(
                    "name"=>$fieldName,
                    "header"=>(strtoupper(CHAR_SET) != 'UTF-8')?utf8_encode($row["field_header"]):$row["field_header"],
                    "type"=>"String",//TODO
                    "fieldId"=>intval($row["qt_field_id"]),
                    "fieldType"=>intval($row["fieldtype_id"]),
                    "dataType"=>intval($row["datatype_id"]),
                    "width"=>intval($row["column_width"]),
                    "searchType"=>intval($row["searchtype_id"]),
                    "fieldFormat"=>$row["field_format"],
                    'editable'=>$userCanEdit ? intval($row['field_editable']) : 0,
                    "resultType"=>intval($row["resultype_id"])
                    //'isPrimaryKey'=>$isPrimaryKey
                );

                if($row["qt_relation_name"]){
                    $fieldSpecs["relationName"] =  $row["qt_relation_name"];
                    $fieldSpecs["relationType"] = intval($row["qtrelationtype_id"]);
                }
                if(intval($row["field_filter"]) > 0){
                    $fieldSpecs["fieldFilter"] =  intval($row["field_filter"]);
                }

                if(!empty($row['lookup_table']) && !empty($row['lookup_id']) && !empty($row['lookup_name'])) {
                    $fieldSpecs['lookup'] = array(
                        'catalog'=>$row['catalog_id'],
                        'table'=>$row['lookup_table'],
                        'id'=>$row['lookup_id'],
                        'name'=>$row['lookup_name']
                    );
                }

                $reportDefs[$index][$reportID]["properties"][] = $fieldSpecs;
            }
        }

        foreach($reportDefs as $index => $arr) {
            foreach($arr as $typeName => $ftype) {
                $this->reportDefs[] = $ftype;
            }
        }
        unset($reportDefs);

    }
}
