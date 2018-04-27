<?php

/*************************************************************************************
 * Base class for mongo models. Extend this class for a new Mongo Record Type
 */
namespace NoSQL;

abstract class CMongoModel extends CNoSqlModel
{
    public static $db;
    private       $_id              = null;
    private       $_pk              = null;
    private       $_isNewRecord     = true;
    private       $_isDeletedRecord = false;
    private       $_initialValues   = [];
    private       $_changeLog       = [];
    private       $_embedded        = [];
    private       $_config          = [];

    /**
     * @var \MongoDB\Client
     */
    private          $_connection = null;
    protected static $MODEL_TB    = null;
    protected static $MODEL_DB    = null;
    protected static $MODEL_PK    = null;

    /******************************************************************************************************
     *
     */
    public function init()
    {
        $this->setDefaults();
    }

    /******************************************************************************************************
     * Available Statuses for the extending Record Type
     * @return array
     */
    public function statusTypes()
    {
        return [];
    }

    /******************************************************************************************************
     * To track the change history of a model record, override this function in the model and return true.
     *
     * @return bool
     */
    public function trackChanges()
    {
        return false;
    }

    /******************************************************************************************************
     * If there is a change history for the current record, return it here
     * @return array
     */
    public final function changeLog()
    {
        if (isset($this->_changeLog)) {
            return $this->_changeLog;
        }

        return [];
    }

    /******************************************************************************************************
     * @param $configGroup
     * @param $configName
     *
     * @return null
     */
    public final function configValue($configGroup, $configName)
    {
        if (isset($this->_config) && isset($this->_config[ $configGroup ][ $configName ])) {
            return $this->_config[ $configGroup ][ $configName ];
        }

        return null;
    }


    /******************************************************************************************************
     * @param $configGroup
     *
     * @return null
     */
    public final function configValues($configGroup=null)
    {
        if(isset($this->_config)){
            if($configGroup != null){
                if (isset($this->_config[ $configGroup ])) {
                    return $this->_config[ $configGroup ];
                }
            }
            return $this->_config;
        }
        return [];
    }

    /******************************************************************************************************
     * @param      $configGroup
     * @param      $configName
     * @param      $value
     * @param bool $autoUpdate
     *
     * @return bool
     */
    public final function configValueSet($configGroup, $configName, $value, $autoUpdate=false)
    {
        if (isset($this->_config)) {
            $this->_config[ $configGroup ][ $configName ] = $value;
            if($autoUpdate==true){
                $this->update();
            }
        }

        return $this;
    }

    /******************************************************************************************************
     *
     */
    public final function configSetDefaults()
    {
        $cc = get_called_class();

        if(!is_callable("{$cc}::configOptions")){
            return;
        }

        $details = $cc::configOptions();

        if (isset($this->_config)) {
            if (is_array($details)) {
                foreach ($details as $detail) {
                    if (
                        !isset($this->_config[ $detail['group'] ])
                        || !isset($this->_config[ $detail['group'] ][ $detail['name'] ])
                        || $this->_config[ $detail['group'] ][ $detail['name'] ] == null
                    ) {
                        $this->_config[ $detail['group'] ][ $detail['name'] ] = isset($detail['default']) ? $detail['default'] : '';
                    }
                }
            }
        }
    }

    /******************************************************************************************************
     * @param $configGroup
     * @param $configName
     *
     * @return null
     */
    public final function configDetails($configGroup,$configName)
    {
        $cc = get_called_class();
        $details = $cc::configOptions();
        if (is_array($details)) {
            foreach ($details as $detail) {
                if ($detail['group'] == $configGroup
                    && $detail['name'] == $configName) {
                    return $detail;
                }
            }
        }

        return null;
    }

    /******************************************************************************************************
     * @param null $group
     *
     * @return array
     */
    public static function configOptions($group = null){
        return [];
    }




    /******************************************************************************************************
     * @param $newId
     *
     * @return $this
     */
    public function setId($newId)
    {
        $this->_id                   = (string)$newId;
        $this->{$this->primaryKey()} = (string)$newId;

        return $this;
    }

    /******************************************************************************************************
     *
     */
    public function afterFind()
    {
        if ($this->trackChanges() == true) {
            $this->_initialValues = $this->getAttributes();

            //
        }
        $this->configSetDefaults();
    }

    /********************************************************************
     * Automatically set typical timestamps
     * @return bool
     */
    public function beforeSave()
    {
        if (\Yii::app()->controller) {
            $sessionedUser = \Yii::app()->controller->sessionGet('UserID');
        }
        else {
            $sessionedUser = 'system';
        }

        if ($this->_isNewRecord) {
            if (isset($this->CreationDate) || in_array('CreationDate', $this->attributeNames())) {
                if (empty($this->CreationDate)) {
                    $this->CreationDate = time();
                }
            }

            if (isset($this->CreatorID) || in_array("CreatorID", (array)$this->attributeNames())) {
                $this->CreatorID = $sessionedUser;
            }
        }

        if (isset($this->LastModified) || in_array('LastModified', $this->attributeNames())) {
            $this->LastModified = time();
        }

        /// TRACK CHANGES
        if ($this->trackChanges() == true) {
            $originalValues = array_diff_assoc($this->_initialValues, $this->getAttributes());
            $newValues      = array_diff_assoc($this->getAttributes(), $this->_initialValues);

            // THE LAST MODIFIED DATE ALWAYS CHANGES NO NEED TO TRACK THAT?
            if (isset($newValues['LastModified'])) {
                unset($newValues['LastModified']);
            }
            if (isset($originalValues['LastModified'])) {
                unset($originalValues['LastModified']);
            }

            /// THIS IS A RUNTIME BASED LOG
            if (!empty($newValues)) {
                $this->_changeLog[] = [
                    "ChangeDate"     => time(),
                    "PreviousValues" => $originalValues,
                    "NewValues"      => $newValues,
                    "ChangedBy"      => $sessionedUser
                ];
            }
        }
    }

    /******************************************************************************************************
     * DO SOMETHING AFTER SAVE
     */
    public function afterSave()
    {
    }

    /******************************************************************************************************
     * @return \MongoDB\Client
     * @throws \CHttpException
     */
    public function getDbConnection()
    {
        try {
            if (empty($this->_connection)) {
                $this->_connection = new \MongoDB\Client($this->getDbConnectionString());
            }
        }
        catch (\Exception $e) {
            throw new \CHttpException(500, 'Could not connect to requested datastore: ' . $e->getMessage());
        }

        return $this->_connection;
    }

    /******************************************************************************************************
     * @return string
     * @throws \CHttpException
     */
    public function getDbConnectionString()
    {
        $datastores = \Yii::app()->params->datastores;

        if (!empty($datastores)) {
            $datastores = (array)$datastores;
            if (isset($datastores[ $this->dbName() ])) {
                $datastore = (object)$datastores[ $this->dbName() ];
            }
            else {
                throw new \CHttpException(500, "Could not find definition for datastore: " . $this->dbName());
            }
        }
        else {
            throw new \CHttpException(500, "No datastores have been defined.");
        }

        $connectionString = "mongodb://{$datastore->ds_user}:{$datastore->ds_pass}@{$datastore->ds_host}:{$datastore->ds_port}";

        return $connectionString;
    }

    /******************************************************************************************************
     * @return $this
     * @throws \CHttpException
     */
    public function insert()
    {
        if ($this->_isDeletedRecord == true) {
            throw new \CHttpException(500, 'Cannot manipulate storage for deleted a record');
        }

        $dbConn    = $this->getDbConnection();
        $tableName = $this->tableName();
        $db        = $dbConn->selectDatabase($this->dbName());

        if ($db) {
            if (method_exists($this, 'beforeSave')) {
                $this->beforeSave();
            }

            $attributes = $this->getAttributes();

            /// HANDLE EMBEDED DOCS
            $embeddables = $this->embeddedDocs();
            if (!empty($embeddables)) {
                foreach ($embeddables as $name => $value) {
                    if ($embeddables[ $name ] == 'class') {
                        try {
                            $attributes[ $name ] = $this->{$name}->getAttributes();
                        }
                        catch (\Exception $e) {
                            throw new \CustHttpException(500, "Could not retrieve attributes for embedded document. {$name} during update in: "
                                                              . get_called_class());
                        }
                    }
                    elseif ($embeddables[ $name ] == 'array') {
                        $attributes[ $name ] = (array)$this->$name;
                    }
                    elseif ($embeddables[ $name ] == 'object') {
                        $attributes[ $name ] = (object)$this->$name;
                    }
                }
            }

            // WE DONT ACTUALLY ADD THE PRIMARY KEY NAME/VALUE
            // ITS JUST AN ALIAS FOR _id
            unset($attributes[ $this->primaryKey() ]);

            if(isset($this->_config)){
                $attributes['_config'] = $this->_config;
            }

            $insertion = $db->{$tableName}->insertOne($attributes);
            if ($insertion->getInsertedCount()) {
                $this->_id                   = $insertion->getInsertedId();
                $this->{$this->primaryKey()} = $this->_id;
                $this->_isNewRecord          = false;
            }
            else {
                throw new \CHttpException(500, "No records reported as inserted.");
            }
        }

        return $this;
    }

    /******************************************************************************************************
     *
     */
    public function refresh()
    {
        if ($this->_isDeletedRecord == true) {
            throw new \CHttpException(500, 'Cannot manipulate storage for deleted a record');
        }
    }

    /******************************************************************************************************
     * @return $this
     * @throws \CHttpException
     */
    public function update()
    {
        if ($this->_isDeletedRecord == true) {
            throw new \CHttpException(500, 'Cannot manipulate storage for deleted a record');
        }

        if (method_exists($this, 'beforeSave')) {
            $this->beforeSave();
        }

        $dbConn     = $this->getDbConnection();
        $attributes = $this->getAttributes();
        $tableName  = $this->tableName();
        $db         = $dbConn->selectDatabase($this->dbName());

        /// HANDLE EMBEDED DOCS
        $embeddables = $this->embeddedDocs();
        if (!empty($embeddables)) {
            foreach ($embeddables as $name => $value) {
                if ($embeddables[ $name ] == 'class') {
                    try {
                        $attributes[ $name ] = $this->{$name}->getAttributes();
                    }
                    catch (\Exception $e) {
                        throw new \CustHttpException(500, "Could not retrieve attributes for embedded document. {$name} during update in: "
                                                          . get_called_class());
                    }
                }
                elseif ($embeddables[ $name ] == 'array') {
                    $attributes[ $name ] = (array)$this->$name;
                }
                elseif ($embeddables[ $name ] == 'object') {
                    $attributes[ $name ] = (object)$this->$name;
                }
            }
        }

        if(isset($this->_config)){
            $attributes['_config'] = $this->_config;
        }

        if ($this->trackChanges() == true) {
            $attributes['_changeLog'] = $this->changeLog();
        }

        $results = $db->{$tableName}->findOneAndUpdate(['_id' => new  \MongoDB\BSON\ObjectID($this->_id)], ['$set' => $attributes]);

        return $this;
    }

    /******************************************************************************************************
     * @return $this
     * @throws \CHttpException
     */
    public function force()
    {
        if ($this->_isDeletedRecord == true) {
            throw new \CHttpException(500, 'Cannot manipulate storage for deleted a record');
        }

        $dbConn     = $this->getDbConnection();
        $attributes = $this->getAttributes();
        $tableName  = $this->tableName();
        $db         = $dbConn->selectDatabase($this->dbName());

        /// HANDLE EMBEDED DOCS
        $embeddables = $this->embeddedDocs();
        if (!empty($embeddables)) {
            foreach ($embeddables as $name => $value) {
                if ($embeddables[ $name ] == 'class') {
                    try {
                        $attributes[ $name ] = $this->{$name}->getAttributes();
                    }
                    catch (\Exception $e) {
                        throw new \CustHttpException(500, "Could not retrieve attributes for embedded document. {$name} during update in: "
                                                          . get_called_class());
                    }
                }
                elseif ($embeddables[ $name ] == 'array') {
                    $attributes[ $name ] = (array)$this->$name;
                }
                elseif ($embeddables[ $name ] == 'object') {
                    $attributes[ $name ] = (object)$this->$name;
                }
            }
        }

        if ($this->trackChanges() == true) {
            $attributes['_changeLog'] = $this->changeLog();
        }

        if (method_exists($this, 'beforeSave')) {
            $this->beforeSave();
        }

        $results = $db->{$tableName}->findOneAndUpdate(['_id' => new  \MongoDB\BSON\ObjectID($this->_id)], ['$set' => $attributes]);

        return $this;
    }

    /******************************************************************************************************
     * @param      $records
     * @param bool $dieOnError
     *
     * @throws \MongoException
     */
    public static function import($records,$dieOnError=false){
        if (is_array($records)) {
            $x = 0;
            $y = count($records);
            foreach ($records as $record) {
                $x++;
                $recordModel = new static();
                $recordModel->setAttributes((array)$record);
                if($recordModel->validate()){
                    try {
                        $recordModel->insert();
                    }
                    catch (\Exception $e) {
                        if($dieOnError){
                            throw new \MongoException("Import failed on item: {$x} of {$y}",1,$e);
                        }
                        continue;
                    }
                }
            }
        }
    }

    /******************************************************************************************************
     *
     */
    public function delete()
    {
        if ($this->_isDeletedRecord == true) {
            throw new \CHttpException(500, 'Cannot manipulate storage for deleted a record');
        }

        $dbConn    = $this->getDbConnection();
        $tableName = $this->tableName();
        $db        = $dbConn->selectDatabase($this->dbName());

        $db->{$tableName}->findOneAndDelete(['_id' => new  \MongoDB\BSON\ObjectID($this->_id)]);

        $this->_isDeletedRecord = true;

        return true;
    }

    /******************************************************************************************************
     * @param      $id
     * @param null $partial
     *
     * @return \NoSQL\CMongoModel|null
     */
    public function findById($id, $partial = null)
    {
        $dbConn    = $this->getDbConnection();
        $tableName = $this->tableName();

        $db = $dbConn->selectDatabase($this->dbName());

        try {
            $result = $db->{$tableName}->findOne(['_id' => new  \MongoDB\BSON\ObjectID($id)]);
        }
        catch (\Exception $e) {
            return null;
        }

        if ($result) {
            $record = $result->jsonSerialize();

            return $this->populateRecord($record, method_exists($this, 'afterFind'));
        }

        return null;
    }

    /******************************************************************************************************
     *
     * ALIAS FOR FIND BY ID
     *
     * @param $id
     *
     * @return mixed
     */
    public function findByPk($id, $partial = null)
    {
        return $this->findById($id, $partial);
    }

    /******************************************************************************************************
     * @param array $filter
     * @param array $options
     *
     * @return \NoSQL\CMongoModel|null
     */
    public function find($filter = [], $options = [])
    {
        $dbConn    = $this->getDbConnection();
        $tableName = $this->tableName();
        $db        = $dbConn->selectDatabase($this->dbName());

        try {
            $result = $db->{$tableName}->findOne((object)$filter, $options);
        }
        catch (\Exception $e) {
            return null;
        }

        if ($result) {
            $record = $result->jsonSerialize();

            return $this->populateRecord($record, method_exists($this, 'afterFind'));
        }

        return null;
    }

    /******************************************************************************************************
     * @param array $filter
     * @param array $options
     *
     * @return \NoSQL\CMongoModel|null
     */
    public function findByAttributes($filter = [], $options = [])
    {
        return $this->find($filter, $options);
    }

    /******************************************************************************************************
     * @param array $filter
     *
     * @return int
     */
    public function countByAttributes($filter = [])
    {
        return $this->count($filter);
    }

    /******************************************************************************************************
     * @param array $filter
     *
     * @return int
     */
    public function count($filter = [])
    {
        $dbConn    = $this->getDbConnection();
        $tableName = $this->tableName();
        $db        = $dbConn->selectDatabase($this->dbName());

        return (int)$db->{$tableName}->count((object)$filter);
    }

    /******************************************************************************************************
     * @param array $filter
     * @param array $options
     *
     * @return array|static[]
     */
    public function findAll($filter = [], $options = [])
    {
        $dbConn    = $this->getDbConnection();
        $tableName = $this->tableName();
        $db        = $dbConn->selectDatabase($this->dbName());

        /**
         * @var $results \MongoDB\Driver\Cursor
         */
        $results = $db->{$tableName}->find((object)$filter, $options);
        if ($results) {
            return $this->populateRecords($results);
        }

        return [];
    }

    /******************************************************************************************************
     * @param string $filter
     * @param array  $options
     *
     * @return static[]
     */
    public function findAllByAttributes($filter = '', $options = [])
    {
        return $this->findAll($filter, $options);
    }

    /******************************************************************************************************
     * @param       string fields
     * @param array $filter
     * @param array $options
     *
     * @return array|static[]
     */
    public function findDistinct($field, $filter = [], $options = [])
    {
        $dbConn    = $this->getDbConnection();
        $tableName = $this->tableName();
        $db        = $dbConn->selectDatabase($this->dbName());

        /**
         * @var $results \MongoDB\Driver\Cursor
         */
        $results = $db->{$tableName}->distinct($field, (object)$filter, $options);
        if ($results) {
            return (array)($results);
        }

        return [];
    }

    /******************************************************************************************************
     * Creates an active record with the given attributes.
     * This method is internally used by the find methods.
     *
     * @param array   $attributes    attribute values (column name=>column value)
     * @param boolean $callAfterFind whether to call {@link afterFind} after the record is populated.
     *
     * @return static the newly created active record. The class of the object is the same as the model class.
     * Null is returned if the input data is false.
     */
    public function populateRecord($attributes, $callAfterFind = true)
    {
        if ($attributes !== false) {
            $record = $this->instantiate($attributes);
            $record->setScenario('update');
            $record->init();
            $embeddables = $this->embeddedDocs();
            foreach ($attributes as $name => $value) {
                if (property_exists($record, $name)) {
                    $record->$name = $value;
                }

                /// HANDLE EMBEDED DOCS
                if (!empty($embeddables) && isset($embeddables[ $name ])) {
                    if ($embeddables[ $name ] == 'class') {
                        if (!$record->$name instanceof $name) {
                            $record->$name = new $name;
                        }
                        $record->$name->setAttributes((array)$value);
                    }
                    elseif ($embeddables[ $name ] == 'array') {
                        $record->$name = (array)$value;
                    }
                    elseif ($embeddables[ $name ] == 'object') {
                        $record->$name = (object)$value;
                    }
                }
            }

            if (isset($attributes->_id)) {
                $record->setId((string)$attributes->_id);

                if (isset($attributes->_changeLog) && isset($record->_changeLog)) {
                    $record->_changeLog = $attributes->_changeLog;
                }
            }


            if(isset($this->_config) && isset($record->_config)){
                $record->_config = (array) $record->_config;
            }
            elseif(isset($this->_config)){
                $this->_config = [];
            }

            $record->attachBehaviors($record->behaviors());

            if ($callAfterFind && method_exists($this, 'afterFind')) {
                $record->afterFind();
            }

            return $record;
        }
        else {
            return null;
        }
    }

    /******************************************************************************************************
     * Creates a list of active records based on the input data.
     * This method is internally used by the find methods.
     *
     * @param array   $data          list of attribute values for the active records.
     * @param boolean $callAfterFind whether to call {@link afterFind} after each record is populated.
     * @param string  $index         the name of the attribute whose value will be used as indexes of the query
     *                               result array. If null, it means the array will be indexed by zero-based
     *                               integers.
     *
     * @return static[] list of active records.
     */
    public function populateRecords($data, $callAfterFind = true, $index = null)
    {
        $records = array();
        foreach ($data as $attributes) {
            if (($record = $this->populateRecord($attributes, $callAfterFind)) !== null) {
                if ($index === null) {
                    $records[] = $record;
                }
                else {
                    $records[ $record->$index ] = $record;
                }
            }
        }

        return $records;
    }

    /******************************************************************************************************
     * @param string $attribute
     *
     * @return bool
     */
    public function attributeEntity($attribute = '')
    {
        return $this->objectType() . '|' . $this->objectId() . '|' . $attribute;
    }

    /******************************************************************************************************
     * @param string $attribute
     *
     * @return bool
     */
    public function isAttribute($attribute = '')
    {
        $attributes = (array)$this->attributeNames();

        return (bool)in_array($attributes, $attribute);
    }

    /******************************************************************************************************
     * @param string $attribute
     * @param        $item
     * @param bool   $saveChanges
     *
     * @return bool
     */
    public function pushAttributeItem($attribute = '', $item, $saveChanges = false)
    {
        if ($this->isAttribute($attribute)) {
            if (empty($this->$attribute)) {
                $this->$attribute = [];
            }

            if (is_array($this->$attribute)) {
                array_push($this->$attribute, $item);

                if ((bool)$saveChanges) {
                    $this->update();
                }

                return true;
            }
        }

        return false;
    }

    /******************************************************************************************************
     * @param string $attribute
     * @param bool   $saveChanges
     *
     * @return bool
     */
    public function popAttributeItem($attribute = '', $saveChanges = false)
    {
        if ($this->isAttribute($attribute)) {
            if (empty($this->$attribute)) {
                $this->$attribute = [];
            }

            if (is_array($this->$attribute)) {
                array_pop($this->$attribute);

                if ((bool)$saveChanges) {
                    $this->update();
                }

                return true;
            }
        }

        return false;
    }

    /******************************************************************************************************
     * @param string $attribute
     * @param        $key
     * @param        $item
     * @param bool   $saveChanges
     *
     * @return bool
     */
    public function setAttributeItem($attribute = '', $key, $item, $saveChanges = false)
    {
        if ($this->isAttribute($attribute)) {
            if (empty($this->$attribute)) {
                $this->$attribute = [];
            }

            if (is_array($this->$attribute)) {
                $this->$attribute[ $key ] = $item;

                if ((bool)$saveChanges) {
                    $this->update();
                }

                return true;
            }
        }

        return false;
    }

    /******************************************************************************************************
     * @param string $attribute
     * @param        $key
     * @param bool   $saveChanges
     *
     * @return bool
     */
    public function unsetAttributeItem($attribute = '', $key, $saveChanges = false)
    {
        if ($this->isAttribute($attribute)) {
            if (empty($this->$attribute)) {
                $this->$attribute = [];
            }

            if (is_array($this->$attribute) && isset($this->$attribute[ $key ])) {
                unset($this->$attribute[ $key ]);

                if ((bool)$saveChanges) {
                    $this->update();
                }

                return true;
            }
        }

        return false;
    }


    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////

    /******************************************************************************************************
     * IS THE MODEL BEING PASSED IN, THE SAME AS THE CALLED THIS METHOD IS BEING
     * CALLED FROM? MAKES USE OF LATE STATIC BINDING
     *
     * @param null $model
     *
     * @return bool
     */
    public static function isSameType($model = null)
    {
        $className = static::className();

        return ($model instanceof $className) ? true : false;
    }

    /******************************************************************************************************
     * ATTEMPT THE FIND THE NEXT RECORD OF THE SAME TYPE OF
     * MODEL BASED THIS CURRENT MODEL'S ID
     * @return mixed
     * @author James Lincoln
     * @date   8/23/2015
     */
    public function getNextRecord()
    {
        $nextRecord = static::model()->find(
            $filters = [
                "_id" => ['$gt' => new  \MongoDB\BSON\ObjectID($this->_id)]
            ],
            $options = [
                "sort"  => ["_id" => 1],
                "limit" => "1"
            ]);

        return $nextRecord;
    }

    /******************************************************************************************************
     * @return mixed
     */
    public function nextRecord()
    {
        return $this->getNextRecord();
    }

    /******************************************************************************************************
     * ATTEMPT THE FIND THE PREVIOUS RECORD OF THE SAME TYPE OF
     * MODEL BASED THIS CURRENT MODEL'S ID
     * @return mixed
     * @author James Lincoln
     * @date   8/23/2015
     */
    public function getPrevRecord()
    {
        // Sort on date ascending and age descending
        //$cursor->sort(array('date' => 1, 'age' => -1));
        // Sort on age descending and date ascending
        //$cursor->sort(array('age' => -1, 'date' => 1));
        $prevRecord = static::model()->find(
            $filters = [
                "_id" => ['$lt' => new  \MongoDB\BSON\ObjectID($this->_id)]
            ],
            $options = [
                "sort"  => ["_id" => -1],
                "limit" => "1"
            ]);

        return $prevRecord;
    }

    /******************************************************************************************************
     * @return mixed
     */
    public function prevRecord()
    {
        return $this->getPrevRecord();
    }

    /******************************************************************************************************
     * SHORTCUT FOR CHECKING/MANAGING STATUS COLUMN. IF AND ONLY IF
     * THE STATUS COLUMN EXISTS FOR THE RECORD SHOULD THIS
     * FUNCTION BE USED.
     *
     * If $status param is empty, the current value of
     * Status column will be returned
     *
     * If $status param is not empty and $set is false,
     * function will return bool true if $status matches
     * current value, false if $status does not match
     * current value
     *
     * If $set is a true value, function will attempt to
     * set Status value to $status
     *
     * @param null       $status
     *
     * @param bool|false $set
     *
     * @return bool|null
     */
    public function status($status = null, $set = false)
    {
        if (!in_array('Status', $this->attributeNames())) {
            return false;
        }

        if (isset($this->Status) && !empty($status) && $set == true) {
            $this->Status = $status;

            return (bool)$this->update();
        }
        elseif (empty($status)) {
            return $this->Status;
        }
        else {
            return ($this->Status == $status) ? true : false;
        }
    }

    /******************************************************************************************************
     * Does the Record have an IsLocked field, which determines if the record can be locked in regards
     * to modifications. Locked records must be unlocked before they can be modified.
     */
    public function isLockable()
    {
        if (!in_array('IsLocked', $this->attributeNames())) {
            return false;
        }

        return true;
    }

    /******************************************************************************************************
     * SHORTCUT FOR CHECKING/MANAGING IsLocked COLUMN. IF AND ONLY IF
     * THE IsLocked COLUMN EXISTS FOR THE RECORD SHOULD THIS
     * FUNCTION BE USED.
     */
    public function lock()
    {
        if (!in_array('IsLocked', $this->attributeNames())) {
            return false;
        }

        if (isset($this->IsLocked) && $this->IsLocked != 1) {
            $this->IsLocked = 1;

            return (bool)$this->update();
        }
    }

    /******************************************************************************************************
     * SHORTCUT FOR CHECKING/MANAGING IsLocked COLUMN. IF AND ONLY IF
     * THE IsLocked COLUMN EXISTS FOR THE RECORD SHOULD THIS
     * FUNCTION BE USED.
     */
    public function unlock()
    {
        if (!in_array('IsLocked', $this->attributeNames())) {
            return false;
        }

        if (isset($this->IsLocked)) {
            $this->IsLocked = 0;

            return (bool)$this->update();
        }
    }

    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////
    /******************************************************************************************************
     * @param       $keyField
     * @param       $valField
     * @param array $filters
     * @param array $orderBy
     * @param bool  $caching
     *
     * @return array
     * @throws \CHttpException
     */
    public static function genList($keyField, $valField, $filters = [], $orderBy = [], $caching = true)
    {
        /// GET THE DEFAULT DB CONNECTION FOR THIS MODEL
        ///
        $dbconn = static::model(static::className())->getDbConnection();
        if (!$dbconn instanceof \MongoDB\Client) {
            throw new \CHttpException('500', "Could not acquire a valid DbConnection for " . __CLASS__);
        }

        /// GENERAL SELECT STATEMENT, BUT LETS MAKE SURE THE ATTRIBUTES ARE DEFINED FOR THE MODEL
        /// CHECKING THAT ATTRIBUTES ARE VALID WILL REALLY HELP IN TYPO SCENARIOS
        $attribs = static::model(static::className())->attributeNames();

        if (!in_array($keyField, $attribs)) {
            throw new \CHttpException('500', "KeyField: {$keyField} is not a valid attribute of "
                                             . static::className());
        }

        $options = [];
        if (!empty($orderBy) && is_array($orderBy)) {
            $options = ['sort' => $orderBy];
        }

        $results = static::model()->findAll($filters, $options);

        $list = array();
        if (!empty($results)) {
            foreach ($results as $record) {
                $list[ $record->$keyField ] = $record->$valField;
            }
        }

        return $list;
    }

    /******************************************************************************************************
     * TRAVERSES THE RULES FOR THE CURRENT MODEL AND CREATES A LIST OF
     * ATTRIBUTES LISTED AS 'REQUIRED'
     * @return array
     */
    public static function requiredAttributes()
    {
        /**
         * @var CMongoModel $class
         */
        $rules          = static::rulesMap();
        $requiredFields = [];
        if (!empty($rules)) {
            foreach ($rules as $rule) {
                if (is_array($rule)) {
                    if (strtolower(trim($rule[1])) == 'required') {
                        $attrs = $rule[0];
                        $attrs = explode(",", $attrs);
                        if (is_array($attrs)) {
                            foreach ($attrs as $attr) {
                                $requiredFields[] = trim($attr);
                            }
                        }
                    }
                }
            }
        }

        return $requiredFields;
    }

    /******************************************************************************************************
     * @param string $attribute
     *
     * @return bool
     */
    public function isAttributeRequired($attribute)
    {
        $requiredAttributes = static::requiredAttributes();
        if (is_array($requiredAttributes) && in_array($attribute, $requiredAttributes)) {
            return true;
        }

        return false;
    }

    /******************************************************************************************************
     * TRAVERSES THE RULES FOR THE CURRENT MODEL AND CREATES A LIST OF
     * ATTRIBUTES LISTED AS 'UNIQUE'
     * @return array
     */
    public static function uniqueAttributes()
    {
        /**
         * @var CMongoModel $class
         */
        $class          = get_called_class();
        $rules          = $class::rulesMap();
        $requiredFields = [];
        if (!empty($rules)) {
            foreach ($rules as $rule) {
                if (is_array($rule)) {
                    if (strtolower(trim($rule[1])) == 'unique') {
                        $attrs = $rule[0];
                        $attrs = explode(",", $attrs);
                        if (is_array($attrs)) {
                            foreach ($attrs as $attr) {
                                $requiredFields[] = trim($attr);
                            }
                        }
                    }
                }
            }
        }

        return $requiredFields;
    }

    /******************************************************************************************************
     * CHECKS TO SEE IF A SPECIFIC MODEL ATTRIBUTE IS LISTED IN A
     * REQUIRED RULE
     *
     * @param null $attr
     * @param null $trueString if you wish to return a string like 'required'
     *
     * @return bool|null
     */
    public static function isRequiredAttribute($attr = null, $trueString = null)
    {
        /**
         * @var CMongoModel $class
         */
        $class          = get_called_class();
        $rules          = $class::rulesMap();
        $requiredFields = $class::requiredAttributes();

        if (in_array($attr, $requiredFields)) {
            if (null !== $trueString) {
                return $trueString;
            }

            return true;
        }

        return false;
    }

    /******************************************************************************************************
     * @param null $attr
     * @param null $trueString
     *
     * @return bool|null
     */
    public function isRequired($attr = null, $trueString = null)
    {
        /**
         * @var CMongoModel $class
         */
        $class = get_called_class();

        return $class::isRequiredAttribute($attr, $trueString);
    }

    /***************************************************************************************************
     * Validate Is Unique Field Value
     *
     * @param $attribute
     * @param $params
     */
    public function validateUnique($attribute, $params)
    {
        $value = $this->$attribute;

        if (isset($params['allowEmpty']) && empty($value)) {
            return;
        }

        if (is_array($value)) {
            $this->addError($attribute, \Yii::t('yii', '{attribute} is invalid.'));

            return;
        }

        if (!empty($this->objectId())) {
            $id      = $this->objectId();
            $matches = static::model()->count(
                [
                    '_id'      => ['$ne' => new  \MongoDB\BSON\ObjectID($id)],
                    $attribute => $value
                ]
            );
        }
        else {
            $matches = static::model()->count(
                [
                    $attribute => $value
                ]
            );
        }

        $message = isset($params['message'])
            ? $params['message']
            : \AppUtils::expandText($attribute)
              . " '{$this->$attribute}' is already in use and is required to be unique.";

        if ((int)$matches > 0) {
            $this->addError($attribute, $message);
        }
    }

    /******************************************************************************************************
     * @param string $dataType
     */
    public function export($dataType = '')
    {
        switch ($dataType) {
            case 'json':
            break;

            case 'xml':
            break;

            case 'serial':
            break;

            case 'array':
            break;
        }
    }

    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////

    /******************************************************************************************************
     * @param array $filters
     * @param array $changes
     *
     * @return mixed
     */
    public static function updateAll($filters = [], $changes = [])
    {
        if (empty($changes)) {
            return false;
        }

        $changingKeys = array_keys($changes);

        $dbConn     = static::model()->getDbConnection();
        $attributes = static::model()->attributeNames();
        $tableName  = static::model()->tableName();
        $db         = $dbConn->selectDatabase(static::model()->dbName());

        // PREVENT KEYS THAT ARE NOT IN THE MODEL FROM BEING SET
        // AT WHOLESALE
        foreach ($changingKeys as $key) {
            if (!in_array($key, $attributes)) {
                unset($changes[ $key ]);
            }
        }

//            if ($this->trackChanges() == true) {
//                $this->_initialValues = $this->getAttributes();
//                $attributes->_changeLog = $this->changeLog();
//            }

        return $results = $db->{$tableName}->updateMany((array)$filters, ['$set' => $changes]);
    }

    /******************************************************************************************************
     * @param array $records
     * @param array $options
     *
     * @return bool
     * @throws \CHttpException
     */
    public static function tableBulkInsert($records = [], $options = [])
    {
        /**
         * @var \NoSQL\CMongoModel $virtual
         */
        $virtual   = new static;
        $dbConn    = $virtual->getDbConnection();
        $tableName = $virtual->tableName();
        $db        = $dbConn->selectDatabase($virtual->dbName());

        try {
            $db->{$tableName}->insertMany($records, $options);
        }
        catch (\MongoDB\Exception\InvalidArgumentException $e) {
            throw new \CHttpException(500, " Bulk Insert action failed: " . $e->getMessage());
        }
        catch (\MongoDB\Exception\UnsupportedException $e) {
            throw new \CHttpException(500, " Bulk Insert action failed: " . $e->getMessage());
        }

        return true;
    }

    /******************************************************************************************************
     * @param array $filters
     * @param array $options
     *
     * @return bool
     * @throws \CHttpException
     */
    public static function tableBulkDelete($filters = [], $options = [])
    {
        /**
         * @var \NoSQL\CMongoModel $virtual
         */
        $virtual = new static;

        if ($virtual->_isDeletedRecord == true) {
            throw new \CHttpException(500, 'Cannot manipulate storage for deleted a record');
        }

        $dbConn    = $virtual->getDbConnection();
        $tableName = $virtual->tableName();
        $db        = $dbConn->selectDatabase($virtual->dbName());

        try {
            $db->{$tableName}->deleteMany($filters, $options);
        }
        catch (\Exception $e) {
            throw new \CHttpException(500, " Bulk Delete action failed: " . $e->getMessage());
        }

        return true;
    }

    /******************************************************************************************************
     *
     */
    public static function tableDrop()
    {
        //MongoCollection::drop ( void )
    }

    /******************************************************************************************************
     *
     */
    public static function tableCopyTo()
    {
        //db.collection.insertMany
    }



    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////
    public static function rulesMap()
    {
        return array();
    }

    public static function tableDataSize()
    {
        //db.collection.dataSize()
    }

    public static function tableTotalSize()
    {
        //db.collection.totalSize()
    }

    public static function tableReIndex()
    {
        //db.collection.reIndex
    }

    public static function tableStats()
    {
        //db.collection.insertMany
    }

    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////
    public static function tableCreateIndex()
    {
        //db.collection.insertMany
    }

    public static function tableCreateIndexes()
    {
        //db.collection.insertMany
    }

    public static function tableDropIndex()
    {
        //db.collection.insertMany
    }

    public static function tableDropIndexes()
    {
        //db.collection.insertMany
    }

    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////
    public static function browser()
    {
        return new CMongoRecordBrowser(static::modelName());
    }


    public static function dumpDataSet($models = [], $format = 'JSON', $ommitObjectId = true, $returnData = false)
    {
        $records = [];
        /**
         * @var $model CMongoModel
         */
        foreach ($models as $model) {
            $a = $model->getAttributes();
            if ($ommitObjectId == true) {
                unset($a[ $model->primaryKey() ]);
            }
            $records[] = $a;
        }

        switch ($format) {
            case 'CSV':
            break;

            case 'SQL':
            break;

            case 'SERIALIZED':
            break;

            case'JSON':
            default:
                $output = json_encode($records);
            break;
        }

        if ($returnData == true) {
        }
    }

    public static function exportDataSet($models = [], $fileName = '', $format = 'JSON', $omitObjectId = true, $returnData = false)
    {
        $records     = [];
        $output      = '';
        $contentType = '';
        $contentExt  = 'txt';
        /**
         * @var $model CMongoModel
         */
        foreach ($models as $model) {
            $a = $model->getAttributes();
            if ($omitObjectId == true) {
                unset($a[ $model->primaryKey() ]);
            }
            $records[] = $a;
        }

        switch ($format) {
            case 'CSV':
                $contentType = 'text/csv';
                $contentExt  = 'csv';
            break;

            case 'SQL':
                $contentType = 'text/sql';
                $contentExt  = 'sql';
            break;

            case 'SERIALIZED':
                $contentType = 'text/plain';
                $contentExt  = 'txt';
            break;

            case'JSON':
            default:
                $contentType = 'text/json';
                $contentExt  = 'json';
                $output      = json_encode($records);
            break;
        }

        if ($returnData == true) {
            return $output;
        }
        else {
            $fileName = !empty($fileName) ? $fileName : $model->className();
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . preg_replace("/\W/", "", $fileName) . '.'
                   . $contentExt . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: Public');
            echo $output;
        }
    }
}