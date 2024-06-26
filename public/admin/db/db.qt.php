<?php
$save=new saveData($_POST);
$p=$save->performAction($p);
$_db = GCApp::getDB();
if($save->action == "salva" && !$save->hasErrors){
	require_once (ADMIN_PATH."lib/functions.php");

	$sql = "select * from ".DB_SCHEMA.".field where relation_id=0 and layer_id=:layerId";
	try {
	    $stmt = $_db->prepare($sql);
	    $stmt->execute(array('layerId' => $_POST["dati"]["layer_id"]));
	    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
	} catch (Exception $e) {
	    GCError::registerException($e);
	    print_debug($sql,null,"elenco");
	}
	if($save->mode == 'new' && is_array($fields)) {
		$qt_id = $save->data['qt_id'];
		//print_debug($fields,null,"elenco");
		foreach ($fields as $field) {
			$newid = GCApp::getNewPKey(DB_SCHEMA, DB_SCHEMA, 'qt_field', 'qt_field_id');
			unset($field['field_id']);
			unset($field['layer_id']);
			$field['qt_field_name'] = $field['field_name'];
			unset($field['field_name']);
			$field['qtfield_order'] = isset($field['field_order'])?$field['field_order']:0;
			unset($field['field_order']);
			unset($field['editable']);
			unset($field['relation_id']);
			$field['qt_id'] =  $qt_id;
			$field['qt_field_id'] = $newid;
			$sql = "insert into ".DB_SCHEMA.".qt_field (qt_field_id, qt_id, qt_field_name, field_header, fieldtype_id, searchtype_id, resultype_id,
			field_format, column_width, orderby_id, field_filter, datatype_id, qtfield_order, default_op, formula, lookup_table, lookup_id, lookup_name, filter_field_name)
				values (:qt_field_id, :qt_id, :qt_field_name, :field_header, :fieldtype_id, :searchtype_id, :resultype_id,
				:field_format, :column_width, :orderby_id, :field_filter, :datatype_id, :qtfield_order, :default_op, :formula, :lookup_table, :lookup_id, :lookup_name, :filter_field_name)";
			try {
				$stmt = $_db->prepare($sql);
				$stmt->execute($field);
			} catch (Exception $e) {
				GCError::registerException($e);
			}
		}
	}
	else if ($save->mode == 'edit' && $_POST["dati"]["materialize"] == 1) {
		require_once (ADMIN_PATH."lib/functions.php");
		$sql = "select catalog_path from ".DB_SCHEMA.".layer l join ".DB_SCHEMA.".catalog c using(catalog_id) where layer_id=:layerId";
		try {
		    $stmt = $_db->prepare($sql);
		    $stmt->execute(array('layerId' => $_POST["dati"]["layer_id"]));
		    $connStr = $stmt->fetchAll(PDO::FETCH_ASSOC)[0]["catalog_path"];
			$datalayerSchema = GCApp::getDataDBSchema($connStr);
	        $tableName = 'gw_qt_' . $_POST["qt"];
	        if (preg_match('/user=([^ ]*)/', $connStr, $charMatches))
		            $connStr = str_replace('user='.$charMatches[1], 'user='.DB_USER, $connStr);
		    if (preg_match('/password=([^ ]*)/', $connStr, $charMatches))
	                $connStr = str_replace('password='.$charMatches[1], 'password='.DB_PWD, $connStr);
	        $dataDB = new GCDataDB($connStr);
			$dataDB->db->query('DROP MATERIALIZED VIEW IF EXISTS ' . $datalayerSchema . '.' . $tableName);
		}
		catch (Exception $e) {
		    GCError::registerException($e);
		    print_debug($sql,null,"report");
		}
	}

}
?>
