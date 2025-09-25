<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of gc_mapObj
 *
 * @author Marco Giraudi
 */

abstract class mapscript {
	static function msGetErrorObj() {
        return ms_GetErrorObj();
    }
	static function msResetErrorList() {
        return ms_ResetErrorList();
    }
	static function msGetVersion() {
        return ms_GetVersion();
    }
	static function msGetVersionInt() {
        return ms_GetVersionInt();
    }
	static function msIO_resetHandlers() {
        return ms_ioresethandlers();
    }
	static function msIO_installStdoutToBuffer() {
        return ms_ioinstallstdouttobuffer();
    }
	static function msIO_installStdinFromBuffer() {
        return ms_ioinstallstdinfrombuffer();
    }
	static function msIO_stripStdoutBufferContentType() {
        return ms_iostripstdoutbuffercontenttype();
    }
	static function msIO_stripStdoutBufferContentHeaders() {
        return ms_iostripstdoutbuffercontentheaders();
    }
	static function msIO_getStdoutBufferString() {
        return ms_iogetstdoutbufferstring();
    }
	static function msIO_getStdoutBufferBytes() {
        ms_iogetStdoutBufferBytes();
        return null;
    }
	static function msLoadMapFromString($map_content) {
		return ms_newMapObjFromString($map_content);
	}
}

trait gc_mcClass {
	public function __set($name, $value) {
			$this->msObj->$name = $value;
	}

	public function __get($name) {
			return $this->msObj->$name;
	}

	public function __call($name, $args) {
			$res = call_user_func_array([$this->msObj, $name], $args);
			return $res;
	}
}

class gc_mapObj {
	use gc_mcClass;
	public $msObj = null;

	public function __construct($map_file_name = '', $new_map_file_name = null) {
		if (!empty($new_map_file_name)) {
			$this->msObj = new mapObj($map_file_name, $new_map_file_name);
		}
		else {
			$this->msObj = new mapObj($map_file_name);
		}
	}

	public function owsDispatch($request) {
		return $this->msObj->owsDispatch($request->msObj);
	}

	public function outputformat_set($property_name, $property_value) {
        if (empty($property_name)) {
            return MS_FAILURE;
        }
        $this->outputformat->set($property_name, $property_value);
    }

    public function extent_set($property_name, $property_value) {
        if (empty($property_name)) {
            return MS_FAILURE;
        }
        $this->extent->set($property_name, $property_value);
    }

    public function web_set($property_name, $property_value) {
        if (empty($property_name)) {
            return MS_FAILURE;
        }
        $this->web->set($property_name, $property_value);
    }

    public function extent_setextent($minx, $miny, $maxx, $maxy) {
        $this->extent->setextent($minx, $miny, $maxx, $maxy);
    }

	public function draw() {
		$img = $this->msObj->draw();
		return new gc_imageObj($img);
	}

	public function embedScalebar($oImage) {
		$img = (property_exists($oImage, 'msObj')) ? $oImage->msObj : $oImage;
		$this->msObj->embedScalebar($img);
	}

	public function drawLabelCache($oImage) {
		$img = (property_exists($oImage, 'msObj')) ? $oImage->msObj : $oImage;
		$this->msObj->drawLabelCache($img);
	}
}

/**
 * Description of gc_layerObj
 *
 * @author Marco Giraudi
 */
class gc_layerObj {
	use gc_mcClass;
	public $msObj = null;
	public function __construct($arg1, $arg2 = null) {
		$arg_1 = (property_exists($arg1, 'msObj')) ? $arg1->msObj : $arg1;
		$arg_2 = (@property_exists($arg2, 'msObj')) ? $arg2->msObj : $arg2;
		if (!empty($arg2)) {
			$this->msObj = new layerObj($arg_1, $arg_2);
		}
		else {
			$this->msObj = new layerObj($arg_1);
		}
	}

	public function setConnectionType($connType, $plugin_lib) {
		if ($plugin_lib == null) {
			$this->msObj->setConnectionType($connType);
		}
		else {
			$this->msObj->setConnectionType($connType, $plugin_lib);
		}
	}
}

/**
 * Description of gc_classObj
 *
 * @author Marco Giraudi
 */
class gc_classObj {
	use gc_mcClass;
	public $msObj = null;
	public function __construct($arg1, $arg2 = null) {
		$arg_1 = (property_exists($arg1, 'msObj')) ? $arg1->msObj : $arg1;
		$arg_2 = (@property_exists($arg2, 'msObj')) ? $arg2->msObj : $arg2;
		if (!empty($arg2)) {
			$this->msObj = new classObj($arg_1, $arg_2);
		}
		else {
			$this->msObj = new classObj($arg_1);
		}
	}

	public function addLabel($lbl) {
		$lbl_1 = (property_exists($lbl, 'msObj')) ? $lbl->msObj : $lbl;
		return $this->msObj->addLabel($lbl_1);
	}
}

/**
 * Description of gc_styleObj
 *
 * @author Marco Giraudi
 */
class gc_styleObj {
	use gc_mcClass;
	public $msObj = null;
	public function __construct($arg1, $arg2 = null) {
		$arg_1 = (property_exists($arg1, 'msObj')) ? $arg1->msObj : $arg1;
		$arg_2 = (@property_exists($arg2, 'msObj')) ? $arg2->msObj : $arg2;
		if (!empty($arg2)) {
			$this->msObj = new styleObj($arg_1, $arg_2);
		}
		else {
			$this->msObj = new styleObj($arg_1);
		}
	}

	public function setSymbolByName($map, $symbolname) {
		$this->msObj->set('symbolname',$symbolname);
	}
}

/**
 * Description of gc_labelObj
 *
 * @author Marco Giraudi
 */
class gc_labelObj {
	use gc_mcClass;
	public $msObj = null;
	public function __construct() {
		$this->msObj = new labelObj();
	}
}

/**
 * Description of gc_rectObj
 *
 * @author Marco Giraudi
 */
class gc_rectObj {
	use gc_mcClass;
	public $msObj = null;
	public function __construct() {
		$this->msObj = new rectObj();
	}
}

/**
 * Description of gc_imageObj
 *
 * @author Marco Giraudi
 */
class gc_imageObj {
	use gc_mcClass;
	public $msObj = null;
	public function __construct($img) {
		$this->msObj = $img;
	}

	public function saveImage($filename, $map) {
		$map_1 = (property_exists($map, 'msObj')) ? $map->msObj : $map;
		return $this->msObj->saveImage($filename, $map_1);
	}
}

/**
 * Description of OWSRequest
 *
 * @author Marco Giraudi
 */
class OWSRequest {
	use gc_mcClass;
	public $msObj = null;
	public function __construct() {
		$this->msObj = new OWSRequestObj();
	}
}

?>
