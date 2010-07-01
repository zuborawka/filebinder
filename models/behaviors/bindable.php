<?php
class BindableBehavior extends ModelBehavior {

    var $settings = array();

    /**
     * setup
     *
     * @param &$model
     * @param $settings
     */
    function setup(&$model, $settings = array()){
        $defaults = array('model' => 'Attachment', // attach model
                          'filePath' => WWW_ROOT . 'bind' . DS, // default attached file path
                          'dbStorage' => true // file entity save table
                          );

        // Merge settings
        $this->settings = Set::merge($defaults, $settings);

        App::import('Model', $this->settings['model']);
        $this->bindedModel =& ClassRegistry::init($this->settings['model']);

        // Set primalyKey
        $this->primalyKey = empty($model->primalyKey) ? 'id' : $model->primalyKey;
    }

    /**
     * beforeValidate
     *
     * @param &$model
     * @return
     */
    function beforeValidate(&$model){
        return true;
    }

    /**
     * afterFind
     *
     * @param &$model, $result
     * @return
     */
    function afterFind(&$model, $result){

        $modelName = $model->name;
        $bindFields = Set::combine($model->bindFields, '/field' , '/');
        $model_ids = Set::extract('/' . $modelName . '/' . $this->primalyKey, $result);

        $query = array();
        $query['recursive'] = -1;
        $query['conditions'] = array('model' => $modelName,
                                     'model_id' => $model_ids);

        $binds = $this->bindedModel->find('all', $query);
        $binds = Set::combine($binds, array('%1$s.%2$s' , '/' . $this->settings['model'] . '/model_id', '/' . $this->settings['model'] . '/field_name'), '/' . $this->settings['model']);

        if (empty($binds)) {
            return $result;
        }

        foreach ($result as $key => $value) {
            $model_id = $value[$modelName][$this->primalyKey];
            foreach ($bindFields as $fieldName => $bindValue) {
                if (array_key_exists($model_id . '.' . $fieldName, $binds)) {
                    $filePath = empty($bindFields[$fieldName]['filePath']) ? $this->settings['filePath'] : $bindFields[$fieldName]['filePath'];
                    $fileName = $binds[$model_id . '.' . $fieldName][$this->settings['model']]['file_name'];
                    $bind = $binds[$model_id . '.' . $fieldName][$this->settings['model']];
                    $bind['file_path'] = $filePath . $modelName . DS . $model_id . DS . $fieldName . DS . $fileName;
                    $result[$key][$modelName][$fieldName] = $bind;
                }
            }
        }

        return $result;
    }

    /**
     * beforeSave
     *
     * @param &$model
     * @return
     */
    function beforeSave(&$model) {
        foreach ($model->data[$model->name] as $fieldName => $value) {
            if (!in_array($fieldName, Set::extract('/field', $model->bindFields))) {
                continue;
            }
            if (empty($value)) {
                continue;
            }

            $bind = $value;
            $bind['model_id'] = 0;

            if ($this->settings['dbStorage']) {
                $tmpFile = $value['tmp_bind_path'];
                $fp = fopen($tmpFile, 'r');
                $ofile = fread($fp, filesize($tmpFile));
                fclose($fp);
                $bind['file_object'] = base64_encode($ofile);
            }

            $this->bindedModel->create();
            if (!$this->bindedModel->save($bind)) {
                return false;
            }

            $bind_id = $this->bindedModel->getLastInsertId();
            $model->data[$model->name][$fieldName]['bind_id']  = $bind_id;
        }

        return true;
    }

    /**
     * afterSave
     *
     * @param &$model
     * @param $created
     * @return
     */
    function afterSave(&$model, $created){
        if ($created) {
            $model_id = $model->getLastInsertId();
        } else {
            $model_id = $model->data[$model->name][$this->primalyKey];
        }

        $bindFields = Set::combine($model->bindFields, '/field' , '/');

        // set model_id
        foreach ($model->data[$model->name] as $fieldName => $value) {
            if (!in_array($fieldName, Set::extract('/field', $model->bindFields))) {
                continue;
            }
            if (empty($value)) {
                continue;
            }

            $bind_id = $value['bind_id'];
            $tmpFile = $value['tmp_bind_path'];

            $bind = array();
            $bind['id'] = $bind_id;
            $bind['model_id'] = $model_id;



            $this->bindedModel->create();
            if (!$this->bindedModel->save($bind)) {
                return false;
            }

            $filePath = empty($bindFields[$fieldName]['filePath']) ? $this->settings['filePath'] : $bindFields[$fieldName]['filePath'];
            $bindDir = $filePath . $value['model'] . DS . $model_id . DS . $fieldName . DS;
            if (file_exists($tmpFile)) {
                if (!file_exists($bindDir)) {
                    mkdir($bindDir, 0755, true);
                }

                rename($tmpFile, $bindDir . $value['file_name']);
            }
        }

    }

    /**
     * beforeDelete
     *
     * @param &$model
     * @return
     */
    function beforeDelete(&$model){
        // Bind model
        $model->bindModel(array('hasMany' => array($this->settings['model'] => array(
                                                                                     'className' => $this->settings['model'],
                                                                                     'foreignKey' => 'model_id',
                                                                                     'dependent' => true,
                                                                                     'conditions' => array($this->settings['model'] . '.model' => $model->name)
                                                                                     ))), false);

        $query = array();
        $query['recursive'] = -1;
        $query['conditions'] = array($model->name . '.' . $this->primalyKey  => $model->id);
        $this->data = $model->find('first', $query);
        return true;
    }

    /**
     * afterDelete
     *
     * @param &$model
     * @return
     */
    function afterDelete(&$model){
        $modelName = $model->name;
        $bindFields = Set::combine($model->bindFields, '/field' , '/');
        $model_id = $this->data[$modelName][$this->primalyKey];
        foreach ($bindFields as $fieldName => $value) {
            $filePath = empty($value['filePath']) ? $this->settings['filePath'] : $value['filePath'];
            $bindDir = $filePath . $modelName . DS . $model_id . DS;
            $this->recursiveRemovemDir($bindDir);
        }
    }

    /**
     * recursiveRemoveDir
     * recursively remove directory
     *
     * @param $dir
     * @return
     */
    function recursiveRemovemDir($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype($dir."/".$object) == "dir") $this->recursiveRemovemDir($dir."/".$object); else unlink($dir."/".$object);
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }

    /**
     * checkExtension
     * Validation method: check extention
     *
     * @param $model
     * @param $value
     * @param $extension
     * @return
     */
    function checkExtension(&$model, $value, $extension){
        $file = array_shift($value);
        if (!is_array($file)) {
            return false;
        }
        if (in_array('allowEmpty', $extension)) {
            $extension = array('jpg', 'gif', 'png'); // set default
        }
        $tmpFilePath = $file['tmp_bind_path'];

        $regexp = '/\.(' . implode('|', (array) $extension) . ')$/';
        if (!preg_match($regexp, $tmpFilePath)) {
            return false;
        }
        return true;
    }

    /**
     * checkContentType
     * Validation method: check MIME type
     *
     * @param &$model
     * @param $value
     * @param $mimeType
     * @return
     */
    function checkContentType(&$model, $value, $mimeType){
        $file = array_shift($value);
        if (!is_array($file)) {
            return false;
        }
        if (in_array('allowEmpty', $mimeType)) {
            return true;
        }

        $contentType = $file['file_content_type'];

        $regexp = '#^(' . implode('|', (array) $mimeType) . ')$#';
        if (!preg_match($regexp, $contentType)) {
            return false;
        }
        return true;
    }

    /**
     * checkFileSize
     * Validation method: check file size
     *
     * @param &$model
     * @param $value
     * @param $max
     * @return
     */
    function checkFileSize(&$model, $value, $max){
        $file = array_shift($value);
        if (!is_array($file)) {
            return false;
        }
        if (in_array('allowEmpty', $max)) {
            return false;
        }

        $fileSize = $file['file_size'];

        if ($fileSize > $max) {
            return false;
        }
        return true;
    }

    /**
     * funcCheckFile
     * Validation method: check file with user function
     *
     * @param &$model
     * @param $value
     * @param $func
     * @return
     */
    function funcCheckFile(&$model, $value, $func){
        $file = array_shift($value);
        if (!is_array($file)) {
            return false;
        }
        if (in_array('allowEmpty', $mimeType)) {
            return false;
        }

        $tmpFilePath = $file['tmp_bind_path'];

        if (!file_exists($tmpFilePath)) {
            return false;
        }

        $result = call_user_func($func, $tmpFilePath);

        return $result;
    }
  }
?>